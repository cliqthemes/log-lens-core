<?php
declare(strict_types=1);

namespace LogLens\Plugins\Ingest;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Domain\LogEvent;
use LogLens\Repositories\IssueRepository;
use LogLens\Services\IngestionSettingsService;
use LogLens\Services\LogImportService;
use LogLens\Plugins\PluginManager;
use LogLens\Services\ModuleService;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use LogLens\Support\SecretBox;
use PDO;

/**
 * HTTP ingest: accept pushed error events over the wire so any app or SDK can
 * report directly, without writing a log file.
 *
 * Pushed events flow through the exact same normalization, grouping, and
 * occurrence pipeline as file-based logs (via {@see IssueRepository::persist})
 * against a synthetic per-channel source file, so they gain titles, grouping,
 * timelines, spike detection, and alerting for free.
 *
 * The endpoint authenticates with a per-application ingest key (a write-only
 * push token, shown once in Settings and rotatable), independent of the
 * dashboard API key.
 */
final class IngestService
{
    private const KEY_SETTING = 'http_ingest.key';
    private const RATE_KEY = 'http_ingest.rate';
    private const RATE_IP_KEY = 'http_ingest.rate.ip';
    private const LOG_TYPE = 'http';
    private const MAX_EVENTS = 500;

    private readonly Connection $db;
    private readonly IssueRepository $issues;
    private readonly PluginManager $plugins;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
        $this->issues = new IssueRepository($this->db);
        $this->plugins = new PluginManager($this->db);
    }

    /**
     * De-minify a browser stack via source maps when the releases plugin is
     * on. Delegated to the plugin system rather than
     * reaching directly into `Plugins\Releases\SourceMapResolver` — this
     * class no longer knows the releases plugin exists.
     */
    private function resolveStack(string $stack, ?string $release): string
    {
        return $this->plugins->resolveStack($stack, $release);
    }

    /**
     * Public config for the settings UI: the push URL and the current key.
     * $baseUrl is the absolute origin+path to build the ingest URL against —
     * the caller passes an explicit LOG_LENS_URL when set, otherwise the base
     * derived from the current request, so nothing assumes a host or TLD.
     *
     * `ingest.url` overrides the derived form outright, for a transport that
     * mounts the receiver somewhere other than `?api=ingest` on the base path.
     * The Laravel adapter sets it, because there the receiver has its own route
     * (`…/log-lens/ingest`) outside the host's access gate and CSRF check.
     */
    public function settings(string $applicationId, string $baseUrl = ''): array
    {
        return [
            'ingest_url' => $this->ingestUrl($applicationId, $baseUrl),
            'key' => $this->ensureKey(),
            'key_header' => 'X-Log-Lens-Ingest-Key',
            'rate_limit' => [
                'max_events' => Config::int('ingest.rate_max_events', 1000),
                'window_seconds' => Config::int('ingest.rate_window_seconds', 60),
            ],
        ];
    }

    private function ingestUrl(string $applicationId, string $baseUrl): string
    {
        $app = 'app=' . rawurlencode($applicationId);

        $configured = rtrim(Config::string('ingest.url', ''), '/');
        if ($configured !== '') {
            return $configured . (str_contains($configured, '?') ? '&' : '?') . $app;
        }

        $base = rtrim($baseUrl !== '' ? $baseUrl : Config::string('LOG_LENS_URL', ''), '/');
        $separator = str_contains($base, '?') ? '&' : ($base === '' ? '?' : '/?');
        return $base . $separator . 'api=ingest&' . $app;
    }

    /** Rotate the ingest key, invalidating any previously distributed one. */
    public function regenerateKey(): array
    {
        $key = $this->newKey();
        $this->storeKey($key);
        return ['key' => $key];
    }

    public function verify(string $presented): bool
    {
        // Load-only: never mint a key here, so an unauthenticated probe can't
        // initialize one. The key is created when an operator opens the Ingest
        // settings (see settings()). No key configured yet ⇒ reject.
        $key = $this->loadKey();
        return $key !== '' && $presented !== '' && hash_equals($key, $presented);
    }

    /**
     * Ingest one event or a batch. Returns a summary. The whole batch runs in a
     * single write transaction so occurrence offsets never race.
     *
     * @param array<string,mixed> $payload
     * @param string|null $clientIp Source IP for the optional per-IP throttle.
     */
    public function ingest(array $payload, ?string $clientIp = null): array
    {
        $events = $this->extractEvents($payload);
        if ($events === []) {
            throw new InvalidArgumentException('No events supplied. Send {"events":[…]} or a single event object.');
        }
        if (count($events) > self::MAX_EVENTS) {
            throw new InvalidArgumentException('A batch may contain at most ' . self::MAX_EVENTS . ' events.');
        }

        $received = count($events);
        $rate = ['allowed' => 0, 'dropped' => $received, 'retry_after' => 0];
        // Was `$this->db->exec('BEGIN IMMEDIATE')` — SQLite-only syntax that
        // threw a hard syntax error on every ingest on Postgres/MySQL (verified
        // live; this endpoint could not accept a single event on either
        // engine). Connection::transaction() is portable across all three;
        // SQLite's write-contention mitigation is the already-configured
        // busy_timeout rather than an upfront "IMMEDIATE" lock.
        $accepted = $this->db->transaction(function (Connection $db) use ($events, $received, $clientIp, &$rate): int {
            // Reserve capacity in the current window before doing any work, so a
            // looping or leaked key cannot balloon the database. Excess events
            // are dropped; a fully-exhausted window yields accepted=0.
            $rate = $this->reserve($received, $clientIp);
            $events = array_slice($events, 0, $rate['allowed']);
            $offsets = [];
            $accepted = 0;
            foreach ($events as $raw) {
                [$logEvent, $moduleId, $sourceKey, $tags, $fingerprint] = $this->buildEvent($raw);
                if (!isset($offsets[$sourceKey])) {
                    $offsets[$sourceKey] = $this->sourceFile($logEvent->channel, $moduleId);
                }
                $byteStart = $offsets[$sourceKey]['offset'];
                $length = max(1, strlen($logEvent->body));
                $offsets[$sourceKey]['offset'] = $byteStart + $length;
                $placed = new LogEvent(
                    $byteStart,
                    $byteStart + $length,
                    $logEvent->occurredAt,
                    $logEvent->severity,
                    $logEvent->environment,
                    $logEvent->body,
                    $logEvent->logType,
                    $logEvent->channel,
                    $logEvent->context,
                    $logEvent->release,
                );
                $eventHash = hash('sha256', implode('|', [
                    $placed->occurredAt, $placed->severity, $placed->channel, $placed->body, (string) $byteStart,
                ]));
                $fp = $this->issues->persist($offsets[$sourceKey]['id'], $placed, $moduleId, $eventHash, $fingerprint);
                if ($fp !== null) {
                    $accepted++;
                    if ($tags !== []) {
                        $this->assignTags($fp, $tags);
                    }
                }
            }
            foreach ($offsets as $file) {
                $db->execute(
                    'UPDATE source_files SET size=?,last_offset=?,imported_at=CURRENT_TIMESTAMP WHERE id=?',
                    [$file['offset'], $file['offset'], $file['id']],
                );
            }
            return $accepted;
        });

        // Recompute aggregates, reapply tag rules, and fire plugin ingest hooks
        // (e.g. alerting) — exactly the same finalize path as file imports.
        if ($accepted > 0) {
            (new LogImportService($this->db))->finalize();
        }

        return array_filter([
            'accepted' => $accepted,
            'received' => $received,
            'dropped' => $rate['dropped'],
            'rate_limited' => $rate['allowed'] === 0 ? true : null,
            'retry_after' => $rate['allowed'] === 0 ? $rate['retry_after'] : null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Reserve up to $requested events for this application in the current
     * window, honoring both the global limit and (when configured and a client
     * IP is known) a secondary per-IP limit. Returns how many are allowed, how
     * many are dropped, and the seconds until the window rolls. Runs inside the
     * ingest write transaction, so concurrent requests serialize on the
     * counters.
     *
     * The limiter is a sliding-window counter over fixed window buckets: usage
     * is the current bucket's count plus the previous bucket's count weighted by
     * the share of the window still overlapping it. That prevents the ~2x burst
     * a fixed window allows at bucket boundaries.
     *
     * @return array{allowed:int,dropped:int,retry_after:int}
     */
    private function reserve(int $requested, ?string $clientIp): array
    {
        $max = Config::int('ingest.rate_max_events', 1000);
        $perIpMax = Config::int('ingest.rate_max_events_per_ip', 0, 0);
        $windowSeconds = max(1, Config::int('ingest.rate_window_seconds', 60));
        $now = time();
        $bucket = intdiv($now, $windowSeconds);
        $elapsed = $now % $windowSeconds;
        // Fraction of the previous bucket still inside the sliding window.
        $prevWeight = ($windowSeconds - $elapsed) / $windowSeconds;
        $retryAfter = max(1, $windowSeconds - $elapsed);

        // Global capacity.
        $global = $this->slidingState($this->readRateRow(self::RATE_KEY), $bucket);
        $allowed = max(0, min($requested, (int) floor($max - $this->slidingUsage($global, $prevWeight))));

        // Optional per-IP capacity narrows (never widens) what is allowed.
        $ipTable = null;
        $usePerIp = $perIpMax > 0 && $clientIp !== null && $clientIp !== '';
        if ($usePerIp) {
            $ipTable = $this->readIpTable($bucket);
            $ipState = $this->slidingState($ipTable[$clientIp] ?? null, $bucket);
            $ipAllowed = max(0, (int) floor($perIpMax - $this->slidingUsage($ipState, $prevWeight)));
            $allowed = min($allowed, $ipAllowed);
        }

        // Commit both counters for exactly $allowed events.
        $global['count'] += $allowed;
        $this->writeRateRow(self::RATE_KEY, $global);
        if ($usePerIp && $ipTable !== null) {
            $ipState = $this->slidingState($ipTable[$clientIp] ?? null, $bucket);
            $ipState['count'] += $allowed;
            $ipTable[$clientIp] = $ipState;
            $this->writeIpTable($ipTable, $bucket, $windowSeconds);
        }

        return [
            'allowed' => $allowed,
            'dropped' => $requested - $allowed,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Normalize a stored `{bucket,count,prev}` state to the current bucket: if
     * the stored bucket is the immediately previous one its count rolls into
     * `prev`; anything older is fully expired.
     *
     * @param array{bucket?:int,count?:int,prev?:int}|null $state
     * @return array{bucket:int,count:int,prev:int}
     */
    private function slidingState(?array $state, int $bucket): array
    {
        $storedBucket = (int) ($state['bucket'] ?? 0);
        $count = max(0, (int) ($state['count'] ?? 0));
        $prev = max(0, (int) ($state['prev'] ?? 0));
        if ($storedBucket === $bucket) {
            return ['bucket' => $bucket, 'count' => $count, 'prev' => $prev];
        }
        if ($storedBucket === $bucket - 1) {
            return ['bucket' => $bucket, 'count' => 0, 'prev' => $count];
        }
        return ['bucket' => $bucket, 'count' => 0, 'prev' => 0];
    }

    /** @param array{bucket:int,count:int,prev:int} $state */
    private function slidingUsage(array $state, float $prevWeight): float
    {
        return $state['count'] + $state['prev'] * $prevWeight;
    }

    /** @return array{bucket?:int,count?:int,prev?:int}|null */
    private function readRateRow(string $key): ?array
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [$key]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array{bucket:int,count:int,prev:int} $state */
    private function writeRateRow(string $key, array $state): void
    {
        $this->db->execute(Dialects::active()->keyValueUpsert(), [$key, json_encode($state, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Read the per-IP counter table, dropping entries whose bucket is older
     * than the previous one (fully expired) so the row cannot grow unbounded.
     *
     * @return array<string,array{bucket:int,count:int,prev:int}>
     */
    private function readIpTable(int $bucket): array
    {
        $raw = $this->readRateRow(self::RATE_IP_KEY);
        $table = [];
        foreach (is_array($raw) ? $raw : [] as $ip => $state) {
            if (!is_string($ip) || !is_array($state)) {
                continue;
            }
            $storedBucket = (int) ($state['bucket'] ?? 0);
            if ($storedBucket >= $bucket - 1) {
                $table[$ip] = [
                    'bucket' => $storedBucket,
                    'count' => max(0, (int) ($state['count'] ?? 0)),
                    'prev' => max(0, (int) ($state['prev'] ?? 0)),
                ];
            }
        }
        return $table;
    }

    /**
     * Persist the per-IP table, capped to the configured maximum number of
     * tracked IPs (evicting the oldest buckets first) to bound the row size.
     *
     * @param array<string,array{bucket:int,count:int,prev:int}> $table
     */
    private function writeIpTable(array $table, int $bucket, int $windowSeconds): void
    {
        $cap = Config::int('ingest.rate_ip_table_max', 1000, 1);
        if (count($table) > $cap) {
            // Keep the most recent buckets, then the busiest, dropping the rest.
            uasort($table, static function (array $a, array $b): int {
                return [$b['bucket'], $b['count']] <=> [$a['bucket'], $a['count']];
            });
            $table = array_slice($table, 0, $cap, true);
        }
        $this->db->execute(Dialects::active()->keyValueUpsert(), [self::RATE_IP_KEY, json_encode($table, JSON_THROW_ON_ERROR)]);
    }

    /** @return list<array<string,mixed>> */
    private function extractEvents(array $payload): array
    {
        if (isset($payload['events']) && is_array($payload['events'])) {
            return array_values(array_filter($payload['events'], 'is_array'));
        }
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }
        if ($payload !== []) {
            return [$payload];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{0:LogEvent,1:?int,2:string}
     */
    private function buildEvent(array $raw): array
    {
        $message = trim((string) ($raw['message'] ?? $raw['title'] ?? ''));
        if ($message === '') {
            throw new InvalidArgumentException('Each event requires a non-empty "message".');
        }
        $severity = strtoupper(trim((string) ($raw['severity'] ?? 'ERROR')));
        if (!in_array($severity, IngestionSettingsService::LEVELS, true)) {
            $severity = 'ERROR';
        }
        $environment = trim((string) ($raw['environment'] ?? 'production')) ?: 'production';
        $channel = $this->sanitizeChannel((string) ($raw['channel'] ?? 'app'));
        $exceptionClass = trim((string) ($raw['exception_class'] ?? $raw['exception'] ?? ''));
        $stack = trim((string) ($raw['stack'] ?? ''));
        $occurredAt = $this->timestamp($raw['occurred_at'] ?? null);
        $release = isset($raw['release']) && trim((string) $raw['release']) !== ''
            ? substr(trim((string) $raw['release']), 0, 120)
            : null;

        // De-minify a browser stack against source maps uploaded for this
        // release (when the releases plugin is enabled), so the source frame and
        // stack read as original code instead of "app.4f2a.js:1:88213".
        if ($stack !== '') {
            $stack = $this->resolveStack($stack, $release);
        }

        // Compose a body the analyzer understands: "Class: message" (so the
        // exception is detected) followed by the stack.
        $body = $exceptionClass !== '' && stripos($message, $exceptionClass) !== 0
            ? $exceptionClass . ': ' . $message
            : $message;
        if ($stack !== '') {
            $body .= "\n" . $stack;
        }

        // Structured first-hand context: request, user,
        // breadcrumbs, and runtime metadata are merged into the stored context.
        $context = isset($raw['context']) && is_array($raw['context']) ? $raw['context'] : [];
        foreach (['request', 'user'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $context[$key] = $raw[$key];
            }
        }
        if (isset($raw['breadcrumbs']) && is_array($raw['breadcrumbs'])) {
            $context['breadcrumbs'] = array_slice(array_values($raw['breadcrumbs']), -50);
        }
        foreach (['server_name', 'runtime'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key]) && trim((string) $raw[$key]) !== '') {
                $context[$key] = substr((string) $raw[$key], 0, 200);
            }
        }

        $fingerprint = isset($raw['fingerprint']) && trim((string) $raw['fingerprint']) !== ''
            ? substr(trim((string) $raw['fingerprint']), 0, 200)
            : null;
        $tags = $this->normalizeTags($raw['tags'] ?? []);

        $moduleId = null;
        if (isset($raw['module']) && trim((string) $raw['module']) !== '') {
            $moduleId = (int) (new ModuleService($this->db))->findOrCreateDirectory(trim((string) $raw['module']))['id'];
        }

        $event = new LogEvent(0, 0, $occurredAt, $severity, $environment, $body, self::LOG_TYPE, $channel, $context, $release);
        return [$event, $moduleId, ($moduleId ?? 0) . '|' . $channel, $tags, $fingerprint];
    }

    /**
     * `channel` is a client-supplied label, not a path — but it is used to
     * build the synthetic `source_files.path` for this event (below), which
     * is later read from disk verbatim by raw-source lookups. Restrict it to
     * a safe slug so a crafted channel (e.g. "../../../../etc/passwd") can't
     * make that lookup escape the intended directory.
     */
    private function sanitizeChannel(string $channel): string
    {
        $channel = basename(trim($channel));
        $channel = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $channel) ?? '';
        $channel = trim($channel, '.-');
        return $channel !== '' ? substr($channel, 0, 120) : 'app';
    }

    /** @return array{id:int,offset:int} */
    private function sourceFile(string $channel, ?int $moduleId): array
    {
        $path = 'http-ingest/' . ($moduleId ?? 0) . '/' . $channel;
        $row = $this->db->selectOne('SELECT id,last_offset FROM source_files WHERE path=?', [$path]);
        if ($row !== null) {
            return ['id' => (int) $row['id'], 'offset' => (int) $row['last_offset']];
        }
        $id = $this->db->insert(
            'INSERT INTO source_files(path,size,modified_at,log_type,channel,module_id) VALUES(?,?,?,?,?,?)',
            [$path, 0, time(), self::LOG_TYPE, $channel, $moduleId],
        );
        return ['id' => $id, 'offset' => 0];
    }

    /** @return list<string> */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $name = trim((string) $tag);
            if ($name === '' || strlen($name) > 80) {
                continue;
            }
            $normalized[strtolower($name)] = $name;
            if (count($normalized) >= 20) {
                break;
            }
        }
        return array_values($normalized);
    }

    /** @param list<string> $tags */
    private function assignTags(string $fingerprint, array $tags): void
    {
        $groupId = (int) ($this->db->selectValue('SELECT id FROM error_groups WHERE fingerprint=?', [$fingerprint]) ?? 0);
        if ($groupId === 0) {
            return;
        }
        $dialect = Dialects::active();
        $insertTagSql = $dialect->insertIgnore('tags', 'name,color,icon', '?,?,?');
        $findTagSql = 'SELECT id FROM tags WHERE ' . $dialect->caseInsensitiveEquals('name');
        $assignSql = $dialect->insertIgnore('error_group_tags', 'group_id,tag_id,source', "?,?,'ingest'");
        foreach ($tags as $name) {
            $this->db->execute($insertTagSql, [$name, '#64748b', 'tag']);
            $tagId = (int) ($this->db->selectValue($findTagSql, [$name]) ?? 0);
            if ($tagId > 0) {
                $this->db->execute($assignSql, [$groupId, $tagId]);
            }
        }
    }

    private function timestamp(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return gmdate('Y-m-d H:i:s', $parsed);
            }
        }
        return gmdate('Y-m-d H:i:s');
    }

    private function ensureKey(): string
    {
        $stored = $this->loadKey();
        if ($stored !== '') {
            return $stored;
        }
        $key = $this->newKey();
        $this->storeKey($key);
        return $key;
    }

    private function loadKey(): string
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [self::KEY_SETTING]);
        return is_string($stored) ? SecretBox::decrypt($stored) : '';
    }

    private function storeKey(string $key): void
    {
        $this->db->execute(Dialects::active()->keyValueUpsert(), [self::KEY_SETTING, SecretBox::encrypt($key)]);
    }

    private function newKey(): string
    {
        return 'llk_' . bin2hex(random_bytes(24));
    }
}
