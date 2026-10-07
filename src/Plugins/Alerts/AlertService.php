<?php
declare(strict_types=1);

namespace LogLens\Plugins\Alerts;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Domain\IssueOrigin;
use LogLens\Services\IngestionSettingsService;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use LogLens\Support\OutboundUrlGuard;
use LogLens\Support\SecretBox;
use PDO;

/**
 * The Alerting plugin's core: manage delivery channels and rules, and scan for
 * alertable conditions after ingestion.
 *
 * Detection is cursor-based: a per-application cursor over the highest seen
 * error-group id and status-history id means every new error and reoccurrence
 * is evaluated exactly once, without reprocessing history. The cursor is
 * initialized to "now" on first run so enabling the plugin never replays a
 * backlog of historical errors as a notification storm.
 */
final class AlertService
{
    public const TRIGGERS = ['new_error', 'reoccurrence', 'spike'];
    private const CURSOR_KEY = 'alerts.cursor';
    private const MAX_ITEMS = 5;

    private readonly Connection $db;

    public function __construct(
        Connection|PDO $db,
        private readonly Notifier $notifier = new Notifier(),
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    // ----- Channels -------------------------------------------------------

    public function channels(): array
    {
        $rows = $this->db->selectAll(
            'SELECT id,name,type,target_token,enabled,created_at,updated_at FROM alert_channels ORDER BY '
            . Dialects::active()->caseInsensitiveOrder('name')
        );
        return array_map(fn (array $row): array => $this->presentChannel($row), $rows);
    }

    public function createChannel(array $input): array
    {
        [$name, $type, $token] = $this->validateChannel($input, requireUrl: true);
        $id = $this->db->insert(
            'INSERT INTO alert_channels(name,type,target_token,enabled) VALUES(?,?,?,?)',
            [$name, $type, $token, (int) ($input['enabled'] ?? true)],
        );
        return $this->channel($id);
    }

    public function updateChannel(int $id, array $input): array
    {
        $existing = $this->channelRow($id);
        [$name, $type, $token] = $this->validateChannel($input, requireUrl: false);
        $this->db->execute(
            'UPDATE alert_channels SET name=?,type=?,target_token=?,enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $name,
                $type,
                $token !== '' ? $token : $existing['target_token'],
                (int) ($input['enabled'] ?? (bool) $existing['enabled']),
                $id,
            ],
        );
        return $this->channel($id);
    }

    public function deleteChannel(int $id): void
    {
        $this->channelRow($id);
        $this->db->execute('DELETE FROM alert_channels WHERE id=?', [$id]);
    }

    public function testChannel(int $id): array
    {
        $row = $this->channelRow($id);
        $result = $this->notifier->deliver($row['type'], SecretBox::decrypt($row['target_token']), [
            'trigger' => 'test',
            'summary' => 'Alerting is connected.',
            'items' => [['severity' => 'INFO', 'title' => 'This is a test notification.']],
            'url' => Config::string('LOG_LENS_URL', ''),
        ]);
        return ['ok' => $result['ok'], 'detail' => $result['detail']];
    }

    // ----- Rules ----------------------------------------------------------

    public function rules(): array
    {
        $rows = $this->db->selectAll(
            "SELECT r.*, c.name channel_name, c.type channel_type,
                    m.name module_name, m.slug module_slug
             FROM alert_rules r
             JOIN alert_channels c ON c.id=r.channel_id
             LEFT JOIN modules m ON m.id=r.module_id
             ORDER BY r.created_at DESC"
        );

        // `enabled` is a boolean to every caller, whatever the engine hands back
        // for it (SQLite/MySQL: int, Postgres: bool) — the UI renders it directly.
        return array_map(static function (array $row): array {
            $row['enabled'] = (bool) $row['enabled'];
            return $row;
        }, $rows);
    }

    public function createRule(array $input): array
    {
        $data = $this->validateRule($input);
        $id = $this->db->insert(
            'INSERT INTO alert_rules(name,channel_id,trigger_type,severities,module_id,min_count,spike_factor,cooldown_minutes,enabled)
             VALUES(?,?,?,?,?,?,?,?,?)',
            [
                $data['name'], $data['channel_id'], $data['trigger_type'], $data['severities'],
                $data['module_id'], $data['min_count'], $data['spike_factor'], $data['cooldown_minutes'], $data['enabled'],
            ],
        );
        return $this->rule($id);
    }

    public function updateRule(int $id, array $input): array
    {
        $this->ruleRow($id);
        $data = $this->validateRule($input);
        $this->db->execute(
            'UPDATE alert_rules SET name=?,channel_id=?,trigger_type=?,severities=?,module_id=?,min_count=?,
                spike_factor=?,cooldown_minutes=?,enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',
            [
                $data['name'], $data['channel_id'], $data['trigger_type'], $data['severities'],
                $data['module_id'], $data['min_count'], $data['spike_factor'], $data['cooldown_minutes'], $data['enabled'], $id,
            ],
        );
        return $this->rule($id);
    }

    public function deleteRule(int $id): void
    {
        $this->ruleRow($id);
        $this->db->execute('DELETE FROM alert_rules WHERE id=?', [$id]);
    }

    public function events(int $limit = 25): array
    {
        return $this->db->selectAll(
            'SELECT e.*, r.name rule_name FROM alert_events e
             LEFT JOIN alert_rules r ON r.id=e.rule_id
             ORDER BY e.id DESC LIMIT ?',
            [max(1, min(200, $limit))],
        );
    }

    // ----- Scan (ingest hook) ---------------------------------------------

    /**
     * Evaluate all enabled rules against activity since the last scan and
     * dispatch consolidated notifications. Safe to call after every import;
     * returns fast when nothing is configured.
     */
    public function scan(): array
    {
        $rules = $this->db->selectAll(
            'SELECT r.*, c.type channel_type, c.target_token, c.enabled channel_enabled
             FROM alert_rules r JOIN alert_channels c ON c.id=r.channel_id
             WHERE r.enabled=1 AND c.enabled=1'
        );
        if ($rules === []) {
            return ['scanned' => false, 'sent' => 0];
        }

        $cursor = $this->cursor();
        if ($cursor === null) {
            $this->saveCursor($this->maxGroupId(), $this->maxHistoryId());
            return ['scanned' => true, 'initialized' => true, 'sent' => 0];
        }

        $newGroups = $this->groupsSince($cursor['group_id']);
        $reoccurred = $this->reoccurrencesSince($cursor['history_id']);
        $maxGroupId = $cursor['group_id'];
        foreach ($newGroups as $group) {
            $maxGroupId = max($maxGroupId, (int) $group['id']);
        }
        $maxHistoryId = $cursor['history_id'];
        foreach ($reoccurred as $group) {
            $maxHistoryId = max($maxHistoryId, (int) $group['history_id']);
        }

        $now = gmdate('Y-m-d H:i:s');
        $sent = 0;
        foreach ($rules as $rule) {
            if (!$this->cooldownElapsed($rule, $now)) {
                continue;
            }
            $matches = match ($rule['trigger_type']) {
                'new_error' => $this->matchGroups($rule, $newGroups, applyMinCount: true),
                'reoccurrence' => $this->matchGroups($rule, $reoccurred, applyMinCount: false),
                'spike' => $this->spikeMatches($rule),
                default => [],
            };
            if ($matches === []) {
                continue;
            }
            if ($this->dispatch($rule, $matches)) {
                $sent++;
                $this->db->execute('UPDATE alert_rules SET last_triggered_at=? WHERE id=?', [$now, $rule['id']]);
            }
        }

        $this->saveCursor($maxGroupId, $maxHistoryId);
        return ['scanned' => true, 'sent' => $sent, 'new_groups' => count($newGroups), 'reoccurrences' => count($reoccurred)];
    }

    private function dispatch(array $rule, array $matches): bool
    {
        $items = array_map(static fn (array $group): array => [
            'severity' => $group['severity'],
            'title' => $group['title'],
            'count' => (int) $group['count'],
        ], array_slice($matches, 0, self::MAX_ITEMS));
        $count = count($matches);
        $noun = $count === 1 ? 'issue' : 'issues';
        $summary = match ($rule['trigger_type']) {
            'reoccurrence' => "{$count} {$noun} reoccurred",
            'spike' => "{$count} {$noun} spiking",
            default => "{$count} new {$noun}",
        };
        $result = $this->notifier->deliver($rule['channel_type'], SecretBox::decrypt($rule['target_token']), [
            'trigger' => $rule['trigger_type'],
            'summary' => $summary,
            'items' => $items,
            'more' => max(0, $count - self::MAX_ITEMS),
            'url' => Config::string('LOG_LENS_URL', ''),
        ]);
        $this->recordEvent(
            (int) $rule['id'],
            (int) $rule['channel_id'],
            (string) $rule['trigger_type'],
            $result['ok'] ? 'sent' : 'failed',
            $result['ok'] ? "{$count} {$noun}" : $result['detail'],
        );
        return $result['ok'];
    }

    /** @return list<array<string,mixed>> */
    private function matchGroups(array $rule, array $groups, bool $applyMinCount): array
    {
        $severities = $this->ruleSeverities($rule);
        $moduleId = $rule['module_id'] === null ? null : (int) $rule['module_id'];
        $minCount = (int) $rule['min_count'];
        return array_values(array_filter($groups, static function (array $group) use ($severities, $moduleId, $applyMinCount, $minCount): bool {
            if ($severities !== [] && !in_array($group['severity'], $severities, true)) {
                return false;
            }
            if ($moduleId !== null && (int) ($group['module_id'] ?? 0) !== $moduleId) {
                return false;
            }
            if ($applyMinCount && (int) $group['count'] < $minCount) {
                return false;
            }
            return true;
        }));
    }

    /** @return list<array<string,mixed>> */
    private function spikeMatches(array $rule): array
    {
        $severities = $this->ruleSeverities($rule);
        $dialect = Dialects::active();
        $oneHourAgo = $dialect->now(-3600);
        $oneDayAgo = $dialect->now(-86400);
        $rows = $this->db->selectAll(
            "SELECT g.id,g.title,g.severity,g.count,g.module_id,
                (SELECT COUNT(*) FROM occurrences o WHERE o.group_id=g.id AND o.occurred_at >= {$oneHourAgo}) recent,
                (SELECT COUNT(*) FROM occurrences o WHERE o.group_id=g.id AND o.occurred_at >= {$oneDayAgo} AND o.occurred_at < {$oneHourAgo}) baseline
             FROM error_groups g
             WHERE EXISTS (SELECT 1 FROM occurrences o WHERE o.group_id=g.id AND o.occurred_at >= {$oneHourAgo})"
        );
        $moduleId = $rule['module_id'] === null ? null : (int) $rule['module_id'];
        $factor = (float) $rule['spike_factor'];
        $minCount = (int) $rule['min_count'];
        $matches = [];
        foreach ($rows as $row) {
            $recent = (int) $row['recent'];
            $hourlyBaseline = ((int) $row['baseline']) / 23;
            if ($recent < max(1, $minCount)) {
                continue;
            }
            if ($hourlyBaseline > 0 && $recent < $factor * $hourlyBaseline) {
                continue;
            }
            if ($hourlyBaseline === 0.0 && $recent < max(2, $minCount)) {
                continue;
            }
            if ($severities !== [] && !in_array($row['severity'], $severities, true)) {
                continue;
            }
            if ($moduleId !== null && (int) $row['module_id'] !== $moduleId) {
                continue;
            }
            $matches[] = $row;
        }
        return $matches;
    }

    // ----- Helpers --------------------------------------------------------

    private function ruleSeverities(array $rule): array
    {
        $decoded = json_decode((string) $rule['severities'], true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function cooldownElapsed(array $rule, string $now): bool
    {
        if (empty($rule['last_triggered_at'])) {
            return true;
        }
        $elapsed = strtotime($now) - strtotime((string) $rule['last_triggered_at']);
        return $elapsed >= (int) $rule['cooldown_minutes'] * 60;
    }

    private function groupsSince(int $groupId): array
    {
        return $this->db->selectAll(
            "SELECT id,title,severity,count,module_id FROM error_groups
             WHERE id > ? AND " . IssueOrigin::occurrenceBackedSql() . " ORDER BY id LIMIT 1000",
            [$groupId],
        );
    }

    private function reoccurrencesSince(int $historyId): array
    {
        return $this->db->selectAll(
            "SELECT h.id history_id,g.id,g.title,g.severity,g.count,g.module_id
             FROM issue_status_history h JOIN error_groups g ON g.id=h.group_id
             WHERE h.id > ? AND h.to_status='reoccurred' ORDER BY h.id LIMIT 1000",
            [$historyId],
        );
    }

    private function maxGroupId(): int
    {
        return (int) $this->db->selectValue('SELECT COALESCE(MAX(id),0) FROM error_groups');
    }

    private function maxHistoryId(): int
    {
        return (int) $this->db->selectValue('SELECT COALESCE(MAX(id),0) FROM issue_status_history');
    }

    /** @return array{group_id:int,history_id:int}|null */
    private function cursor(): ?array
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [self::CURSOR_KEY]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($decoded) || !isset($decoded['group_id'], $decoded['history_id'])) {
            return null;
        }
        return ['group_id' => (int) $decoded['group_id'], 'history_id' => (int) $decoded['history_id']];
    }

    private function saveCursor(int $groupId, int $historyId): void
    {
        $this->db->execute(
            Dialects::active()->keyValueUpsert(),
            [self::CURSOR_KEY, json_encode(['group_id' => $groupId, 'history_id' => $historyId], JSON_THROW_ON_ERROR)],
        );
    }

    private function recordEvent(int $ruleId, int $channelId, string $trigger, string $status, string $detail): void
    {
        $this->db->execute(
            'INSERT INTO alert_events(rule_id,channel_id,trigger_type,status,detail) VALUES(?,?,?,?,?)',
            [$ruleId, $channelId, $trigger, $status, $detail],
        );
    }

    private function channel(int $id): array
    {
        return $this->presentChannel($this->channelRow($id));
    }

    private function channelRow(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM alert_channels WHERE id=?', [$id]);
        if ($row === null) {
            throw new \LogLens\Domain\NotFoundException('Alert channel not found.');
        }
        return $row;
    }

    private function rule(int $id): array
    {
        $row = $this->db->selectOne(
            "SELECT r.*, c.name channel_name, c.type channel_type, m.name module_name, m.slug module_slug
             FROM alert_rules r JOIN alert_channels c ON c.id=r.channel_id
             LEFT JOIN modules m ON m.id=r.module_id WHERE r.id=?",
            [$id],
        );
        if ($row === null) {
            throw new \LogLens\Domain\NotFoundException('Alert rule not found.');
        }
        return $row;
    }

    private function ruleRow(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM alert_rules WHERE id=?', [$id]);
        if ($row === null) {
            throw new \LogLens\Domain\NotFoundException('Alert rule not found.');
        }
        return $row;
    }

    /** @return array{0:string,1:string,2:string} name,type,token */
    private function validateChannel(array $input, bool $requireUrl): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $url = trim((string) ($input['url'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Channel name must be 1–120 characters.');
        }
        if (!in_array($type, Notifier::TYPES, true)) {
            throw new InvalidArgumentException('Channel type must be slack, discord, or webhook.');
        }
        if ($url !== '') {
            if (!preg_match('#^https://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Channel URL must be a valid https:// URL.');
            }
            $this->rejectInternalUrl($url);
        } elseif ($requireUrl) {
            throw new InvalidArgumentException('A channel URL is required.');
        }
        return [$name, $type, $url !== '' ? SecretBox::encrypt($url) : ''];
    }

    /**
     * Alert channel URLs are delivered to by the server itself (Notifier ->
     * HttpClient), so an operator who can configure a channel could otherwise
     * point it at an internal service (loopback, RFC1918, link-local/cloud
     * metadata) and use "Test connection" or a live alert as a blind SSRF
     * probe. Reject any URL whose host resolves to a non-public address.
     *
     * This check exists to fail early with a message the operator sees while
     * they are still looking at the form. It is not the control that holds:
     * DNS can answer differently by delivery time, so HttpClient re-validates
     * and pins the destination on every attempt. Both call the same guard —
     * see {@see \LogLens\Support\OutboundUrlGuard}.
     */
    private function rejectInternalUrl(string $url): void
    {
        try {
            OutboundUrlGuard::publicIps($url);
        } catch (\RuntimeException $exception) {
            // Re-typed: at this point the URL is form input being validated, so
            // the caller answers 422 rather than treating it as a server fault.
            throw new InvalidArgumentException('Channel URL rejected: ' . $exception->getMessage());
        }
    }

    private function validateRule(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Rule name must be 1–120 characters.');
        }
        $channelId = (int) ($input['channel_id'] ?? 0);
        $this->channelRow($channelId);
        $trigger = strtolower(trim((string) ($input['trigger_type'] ?? '')));
        if (!in_array($trigger, self::TRIGGERS, true)) {
            throw new InvalidArgumentException('Trigger must be new_error, reoccurrence, or spike.');
        }
        $severities = is_array($input['severities'] ?? null) ? $input['severities'] : [];
        $severities = array_values(array_filter(array_map(
            static fn ($level): string => strtoupper(trim((string) $level)),
            $severities,
        ), static fn (string $level): bool => in_array($level, IngestionSettingsService::LEVELS, true)));
        $moduleId = ($input['module_id'] ?? null) === null || $input['module_id'] === ''
            ? null
            : (int) $input['module_id'];
        return [
            'name' => $name,
            'channel_id' => $channelId,
            'trigger_type' => $trigger,
            'severities' => json_encode($severities, JSON_THROW_ON_ERROR),
            'module_id' => $moduleId,
            'min_count' => max(1, (int) ($input['min_count'] ?? 1)),
            'spike_factor' => max(1.5, (float) ($input['spike_factor'] ?? 3)),
            'cooldown_minutes' => max(0, (int) ($input['cooldown_minutes'] ?? 60)),
            'enabled' => (int) ($input['enabled'] ?? true),
        ];
    }

    private function presentChannel(array $row): array
    {
        $host = '';
        $url = SecretBox::decrypt((string) $row['target_token']);
        if ($url !== '') {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        }
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'enabled' => (bool) $row['enabled'],
            'target_hint' => $host,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
