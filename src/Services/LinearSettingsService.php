<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Linear\LinearClient;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use LogLens\Support\SecretBox;
use PDO;

/**
 * Per-application Linear integration configuration, persisted as one JSON blob
 * in app_settings. The integration is entirely optional: an application with no
 * stored settings (or `enabled=false`) behaves exactly as before.
 *
 * Secret handling follows a clear precedence so operators can keep credentials
 * out of the database entirely:
 *
 *   1. `LOG_LENS_LINEAR_API_KEY` (config `linear.api_key`) — when set it is the
 *      source of truth, is never written to the database, and cannot be
 *      overwritten from the UI.
 *   2. A UI-entered key — encrypted at rest via {@see SecretBox} using
 *      `LOG_LENS_SECRET`. Without that deployment secret the key is stored in a
 *      clearly-marked unencrypted envelope and `configuration()` reports it, so
 *      the operator can choose to harden the deployment.
 */
final class LinearSettingsService
{
    private const SETTING_KEY = 'linear.integration';
    private const MAX_LABELS = 50;

    private readonly Connection $db;

    public function __construct(Connection|PDO $db)
    {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** The raw stored settings, with safe defaults for a fresh workspace. */
    public function raw(): array
    {
        $stored = $this->db->selectValue(Dialects::active()->keyValueLookup(), [self::SETTING_KEY]);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $data = is_array($decoded) ? $decoded : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'labels' => $this->normalizeLabels($data['labels'] ?? []),
            'assigned_to_me' => (bool) ($data['assigned_to_me'] ?? false),
            'assignees' => $this->normalizeAssignees($data['assignees'] ?? []),
            'status_writeback' => (bool) ($data['status_writeback'] ?? false),
            // Default for the per-status-change "also comment" choice a caller
            // (e.g. the Inspector's checkbox) makes each time — a persisted
            // preference, not itself enforced anywhere: whoever changes status
            // still decides, and this only seeds where they start from.
            'status_writeback_comment' => (bool) ($data['status_writeback_comment'] ?? true),
            'team_key' => trim((string) ($data['team_key'] ?? '')),
            'api_key_token' => (string) ($data['api_key_token'] ?? ''),
            'webhook_secret_token' => (string) ($data['webhook_secret_token'] ?? ''),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
            // Resumable-pull bookkeeping (never user-editable; see syncState()/
            // recordSyncProgress()) — kept in this same blob for simplicity
            // rather than a second settings key.
            'sync_cursor' => (string) ($data['sync_cursor'] ?? ''),
            'backfill_complete' => (bool) ($data['backfill_complete'] ?? false),
            'synced_through' => (string) ($data['synced_through'] ?? ''),
            'sync_floor' => (string) ($data['sync_floor'] ?? ''),
        ];
    }

    /** Public, secret-free view for the settings API and UI. */
    public function configuration(): array
    {
        $raw = $this->raw();
        $envKey = Config::string('linear.api_key', '');
        $source = $envKey !== '' ? 'env' : ($raw['api_key_token'] !== '' ? 'stored' : 'none');

        return [
            'enabled' => $raw['enabled'],
            'labels' => $raw['labels'],
            'assigned_to_me' => $raw['assigned_to_me'],
            'assignees' => $raw['assignees'],
            'status_writeback' => $raw['status_writeback'],
            'status_writeback_comment' => $raw['status_writeback_comment'],
            'team_key' => $raw['team_key'],
            'api_key_configured' => $source !== 'none',
            'api_key_source' => $source,
            'webhook_configured' => $raw['webhook_secret_token'] !== '' || Config::string('linear.webhook_secret', '') !== '',
            // True at-rest encryption is only possible with a deployment secret;
            // surface it so operators know whether a stored key is protected.
            'at_rest_encrypted' => SecretBox::secured(),
            'env_key_locked' => $envKey !== '',
            'updated_at' => $raw['updated_at'],
            'sync' => [
                // false until the very first full pull has drained every page;
                // from then on every sync() is incremental (updated-since only).
                'backfill_complete' => $raw['backfill_complete'],
                'synced_through' => $raw['synced_through'] !== '' ? $raw['synced_through'] : null,
                'in_progress' => $raw['sync_cursor'] !== '',
            ],
        ];
    }

    /**
     * Persist a settings update. Only the keys present in $input are changed;
     * secrets are left untouched when omitted and cleared when an explicit empty
     * string is sent.
     */
    public function update(array $input): array
    {
        $raw = $this->raw();
        // Any change to which issues match invalidates the resumable cursor and
        // high-water mark — a cursor/floor recorded under the old selection
        // doesn't mean anything under the new one, so start the pull over.
        $filterKeys = ['labels', 'assigned_to_me', 'assignees', 'team_key'];
        if (array_intersect($filterKeys, array_keys($input)) !== []) {
            $this->resetSyncState($raw);
        }

        if (array_key_exists('labels', $input)) {
            if (!is_array($input['labels'])) {
                throw new InvalidArgumentException('labels must be an array of label names.');
            }
            $raw['labels'] = $this->normalizeLabels($input['labels']);
        }
        if (array_key_exists('assigned_to_me', $input)) {
            $raw['assigned_to_me'] = $this->boolean($input['assigned_to_me']);
        }
        if (array_key_exists('assignees', $input)) {
            if (!is_array($input['assignees'])) {
                throw new InvalidArgumentException('assignees must be an array of emails or Linear user ids.');
            }
            $raw['assignees'] = $this->normalizeAssignees($input['assignees']);
        }
        if (array_key_exists('status_writeback', $input)) {
            $raw['status_writeback'] = $this->boolean($input['status_writeback']);
        }
        if (array_key_exists('status_writeback_comment', $input)) {
            $raw['status_writeback_comment'] = $this->boolean($input['status_writeback_comment']);
        }
        if (array_key_exists('team_key', $input)) {
            $teamKey = strtoupper(trim((string) $input['team_key']));
            if ($teamKey !== '' && preg_match('/^[A-Z0-9]{1,20}$/', $teamKey) !== 1) {
                throw new InvalidArgumentException('team_key must be a short alphanumeric Linear team key.');
            }
            $raw['team_key'] = $teamKey;
        }
        if (array_key_exists('api_key', $input)) {
            if (Config::string('linear.api_key', '') !== '') {
                throw new InvalidArgumentException(
                    'The Linear API key is provided by the LOG_LENS_LINEAR_API_KEY environment variable and cannot be changed here.'
                );
            }
            $key = trim((string) $input['api_key']);
            $raw['api_key_token'] = $key === '' ? '' : SecretBox::encrypt($key);
        }
        if (array_key_exists('webhook_secret', $input)) {
            $secret = trim((string) $input['webhook_secret']);
            $raw['webhook_secret_token'] = $secret === '' ? '' : SecretBox::encrypt($secret);
        }
        if (array_key_exists('enabled', $input)) {
            $raw['enabled'] = $this->boolean($input['enabled']);
        }

        if ($raw['enabled'] && !$this->hasApiKeyWith($raw)) {
            throw new InvalidArgumentException(
                'Add a Linear API key (or set LOG_LENS_LINEAR_API_KEY) before enabling the integration.'
            );
        }

        $raw['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            Dialects::active()->keyValueUpsert(),
            [self::SETTING_KEY, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        );

        return $this->configuration();
    }

    public function isEnabled(): bool
    {
        return $this->raw()['enabled'] && $this->hasApiKey();
    }

    public function statusWritebackEnabled(): bool
    {
        return $this->isEnabled() && $this->raw()['status_writeback'];
    }

    /**
     * The resumable-pull bookkeeping {@see LinearSyncService::sync()} reads at
     * the start of every call.
     *
     * @return array{cursor:?string,backfill_complete:bool,synced_through:?string,sync_floor:?string}
     */
    public function syncState(): array
    {
        $raw = $this->raw();
        return [
            'cursor' => $raw['sync_cursor'] !== '' ? $raw['sync_cursor'] : null,
            'backfill_complete' => $raw['backfill_complete'],
            'synced_through' => $raw['synced_through'] !== '' ? $raw['synced_through'] : null,
            'sync_floor' => $raw['sync_floor'] !== '' ? $raw['sync_floor'] : null,
        ];
    }

    /** Persist where the next sync() call should resume from. */
    public function recordSyncProgress(?string $cursor, bool $backfillComplete, ?string $syncedThrough, ?string $syncFloor): void
    {
        $raw = $this->raw();
        $raw['sync_cursor'] = $cursor ?? '';
        $raw['backfill_complete'] = $backfillComplete;
        $raw['synced_through'] = $syncedThrough ?? '';
        $raw['sync_floor'] = $syncFloor ?? '';
        $this->db->execute(
            Dialects::active()->keyValueUpsert(),
            [self::SETTING_KEY, json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)],
        );
    }

    /** @param array<string,mixed> $raw */
    private function resetSyncState(array &$raw): void
    {
        $raw['sync_cursor'] = '';
        $raw['backfill_complete'] = false;
        $raw['synced_through'] = '';
        $raw['sync_floor'] = '';
    }

    public function hasApiKey(): bool
    {
        return $this->hasApiKeyWith($this->raw());
    }

    /** Resolve the effective API key: environment override wins over stored. */
    public function apiKey(): string
    {
        $envKey = Config::string('linear.api_key', '');
        if ($envKey !== '') {
            return $envKey;
        }
        return SecretBox::decrypt($this->raw()['api_key_token']);
    }

    /** Resolve the effective webhook signing secret ('' when unset). */
    public function webhookSecret(): string
    {
        $envSecret = Config::string('linear.webhook_secret', '');
        if ($envSecret !== '') {
            return $envSecret;
        }
        return SecretBox::decrypt($this->raw()['webhook_secret_token']);
    }

    public function client(?callable $transport = null): LinearClient
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new InvalidArgumentException('No Linear API key is configured.');
        }
        return new LinearClient(
            $key,
            Config::string('linear.endpoint', LinearClient::DEFAULT_ENDPOINT),
            Config::int('linear.timeout', 15),
            $transport,
        );
    }

    private function hasApiKeyWith(array $raw): bool
    {
        return Config::string('linear.api_key', '') !== '' || $raw['api_key_token'] !== '';
    }

    /** @return list<string> */
    private function normalizeLabels(mixed $labels): array
    {
        if (!is_array($labels)) {
            return [];
        }
        $normalized = [];
        foreach ($labels as $label) {
            if (!is_scalar($label)) {
                continue;
            }
            $name = trim((string) $label);
            if ($name === '' || strlen($name) > 80) {
                continue;
            }
            $normalized[strtolower($name)] = $name;
            if (count($normalized) >= self::MAX_LABELS) {
                break;
            }
        }
        return array_values($normalized);
    }

    /**
     * Normalize an explicit assignee list. Each entry is either an email
     * address or a Linear user id (UUID); both are kept verbatim (emails
     * lower-cased) and de-duplicated. This is how "assigned to a specific
     * person" works regardless of whose API key is configured.
     *
     * @return list<string>
     */
    private function normalizeAssignees(mixed $assignees): array
    {
        if (!is_array($assignees)) {
            return [];
        }
        $normalized = [];
        foreach ($assignees as $assignee) {
            if (!is_scalar($assignee)) {
                continue;
            }
            $value = trim((string) $assignee);
            if ($value === '' || strlen($value) > 200) {
                continue;
            }
            // Lower-case emails for stable de-duplication; leave ids untouched.
            $key = str_contains($value, '@') ? strtolower($value) : $value;
            $normalized[$key] = str_contains($value, '@') ? strtolower($value) : $value;
            if (count($normalized) >= self::MAX_LABELS) {
                break;
            }
        }
        return array_values($normalized);
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
