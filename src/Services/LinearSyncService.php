<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Dialects;

use InvalidArgumentException;
use LogLens\Config;
use LogLens\Domain\IssueOrigin;
use LogLens\Linear\LinearClient;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use PDO;

/**
 * Pulls matching Linear issues into the local issue store and, when enabled,
 * links Log Lens workflow-status changes back to Linear.
 *
 * Linear issues are stored as ordinary rows in `error_groups` with
 * `origin='linear'`, keyed by a deterministic fingerprint derived from the
 * Linear issue id so repeated syncs update in place instead of duplicating.
 * This deliberately does NOT reuse the byte-mirroring connector pipeline, which
 * is designed for append-only log files rather than structured records.
 */
final class LinearSyncService
{
    private const SOURCE = IssueOrigin::LINEAR;

    private readonly Connection $db;

    public function __construct(
        Connection|PDO $db,
        private readonly LinearSettingsService $settings,
        private readonly ?LinearClient $clientOverride = null,
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /** Verify credentials and report the authenticated Linear user. */
    public function test(): array
    {
        $viewer = $this->client()->viewer();
        return ['ok' => true, 'viewer' => $viewer];
    }

    /**
     * Pull every matching issue and upsert it. Returns a per-run summary.
     */
    public function sync(): array
    {
        if (!$this->settings->isEnabled()) {
            throw new InvalidArgumentException('The Linear integration is not enabled for this application.');
        }
        $client = $this->client();
        $filter = $this->buildFilter();
        $issues = $client->fetchIssues($filter);

        $created = 0;
        $updated = 0;
        $tagsCreated = 0;
        foreach ($issues as $issue) {
            $result = $this->upsertIssue($issue);
            $created += $result['created'];
            $updated += $result['updated'];
            $tagsCreated += $result['tags_created'];
        }

        return [
            'ok' => true,
            'pulled' => count($issues),
            'created' => $created,
            'updated' => $updated,
            'tags_created' => $tagsCreated,
            'synced_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Link a Log Lens status change back to Linear for a linear-sourced issue.
     * Always comments; additionally transitions the Linear workflow state for
     * terminal/started statuses when a matching state exists. Never throws —
     * write-back must not break the local status change.
     */
    public function onStatusChanged(int $groupId, string $status, string $note = ''): array
    {
        if (!$this->settings->statusWritebackEnabled()) {
            return ['attempted' => false];
        }
        $externalId = (string) ($this->db->selectValue(
            "SELECT external_id FROM error_groups WHERE id=? AND origin='" . IssueOrigin::LINEAR . "' AND external_source=?",
            [$groupId, self::SOURCE],
        ) ?: '');
        if ($externalId === '') {
            return ['attempted' => false];
        }

        $result = ['attempted' => true, 'ok' => false, 'commented' => false, 'transitioned' => false];
        try {
            $client = $this->client();
            $label = ucwords(str_replace('_', ' ', $status));
            $body = "Log Lens marked this issue **{$label}**.";
            if (trim($note) !== '') {
                $body .= "\n\n> " . trim($note);
            }
            $client->createComment($externalId, $body);
            $result['commented'] = true;

            $targetType = self::STATUS_TO_STATE_TYPE[$status] ?? null;
            if ($targetType !== null) {
                $result['transitioned'] = $this->transition($client, $externalId, $targetType);
            }
            $result['ok'] = true;
        } catch (\Throwable $exception) {
            $result['error'] = $exception->getMessage();
        }
        return $result;
    }

    /** Best-effort transition to the first team state of the given type. */
    private function transition(LinearClient $client, string $externalId, string $targetType): bool
    {
        $issue = $client->issueForTransition($externalId);
        $states = $issue['team']['states']['nodes'] ?? [];
        foreach ($states as $state) {
            if (($state['type'] ?? null) === $targetType && isset($state['id'])) {
                $client->updateIssueState($externalId, (string) $state['id']);
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function buildFilter(): array
    {
        $raw = $this->settings->raw();
        $clauses = [];
        if ($raw['labels'] !== []) {
            $clauses[] = ['labels' => ['some' => ['name' => ['in' => $raw['labels']]]]];
        }
        $assigneeClause = $this->assigneeClause($raw['assigned_to_me'], $raw['assignees']);
        if ($assigneeClause !== []) {
            $clauses[] = $assigneeClause;
        }

        // "Issues with these labels OR assigned to (me / these people)" — an OR
        // across the selection clauses, matching the operator's mental model.
        $match = [];
        if (count($clauses) === 1) {
            $match = $clauses[0];
        } elseif (count($clauses) > 1) {
            $match = ['or' => $clauses];
        }

        if ($raw['team_key'] !== '') {
            $team = ['team' => ['key' => ['eq' => $raw['team_key']]]];
            if ($match === []) {
                $match = $team;
            } elseif (isset($match['or'])) {
                $match = ['and' => [$team, $match]];
            } else {
                $match = $team + $match;
            }
        }

        if ($match === []) {
            // Never pull an entire workspace by accident.
            throw new InvalidArgumentException(
                'Configure at least one Linear filter (labels, assigned-to-me, specific assignees, or a team key) before syncing.'
            );
        }
        return $match;
    }

    /**
     * Build the assignee selection: any of "assigned to me" (the API key's
     * owner) or a list of explicit people identified by email or Linear user
     * id. Returns [] when no assignee filter is configured.
     *
     * @param list<string> $assignees
     * @return array<string,mixed>
     */
    private function assigneeClause(bool $assignedToMe, array $assignees): array
    {
        $emails = [];
        $ids = [];
        foreach ($assignees as $assignee) {
            if (str_contains($assignee, '@')) {
                $emails[] = $assignee;
            } else {
                $ids[] = $assignee;
            }
        }

        $options = [];
        if ($assignedToMe) {
            $options[] = ['assignee' => ['isMe' => ['eq' => true]]];
        }
        if ($emails !== []) {
            $options[] = ['assignee' => ['email' => ['in' => $emails]]];
        }
        if ($ids !== []) {
            $options[] = ['assignee' => ['id' => ['in' => $ids]]];
        }

        if (count($options) === 1) {
            return $options[0];
        }
        if (count($options) > 1) {
            return ['or' => $options];
        }
        return [];
    }

    /** @param array<string,mixed> $issue */
    private function upsertIssue(array $issue): array
    {
        $externalId = (string) ($issue['id'] ?? '');
        if ($externalId === '') {
            return ['created' => 0, 'updated' => 0, 'tags_created' => 0];
        }
        $fingerprint = hash('sha256', 'linear|' . $externalId);
        $identifier = (string) ($issue['identifier'] ?? '');
        $title = $this->truncate((string) ($issue['title'] ?? $identifier ?: 'Untitled Linear issue'), 500);
        $description = $this->truncate((string) ($issue['description'] ?? ''), Config::int('ingestion.message_limit', 120000));
        $severity = $this->severity($issue['priority'] ?? null);
        $status = $this->status($issue['state']['type'] ?? null);
        $url = (string) ($issue['url'] ?? '');
        $assignee = $this->assigneeLabel($issue['assignee'] ?? null);
        $teamKey = (string) ($issue['team']['key'] ?? '');
        $firstSeen = $this->timestamp($issue['createdAt'] ?? null);
        $lastSeen = $this->timestamp($issue['updatedAt'] ?? null);
        $context = json_encode([
            'origin' => IssueOrigin::LINEAR,
            'identifier' => $identifier,
            'state' => $issue['state']['name'] ?? null,
            'priority' => $issue['priorityLabel'] ?? null,
            'team' => $issue['team']['name'] ?? null,
            'assignee' => $assignee,
            'url' => $url,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->db->transaction(function (Connection $db) use (
            $fingerprint, $severity, $title, $identifier, $firstSeen, $lastSeen, $description,
            $context, $status, $teamKey, $externalId, $url, $assignee, $issue,
        ): array {
            $created = 0;
            $updated = 0;
            $groupId = (int) ($db->selectValue('SELECT id FROM error_groups WHERE fingerprint=?', [$fingerprint]) ?: 0);

            if ($groupId === 0) {
                $groupId = $db->insert(
                    'INSERT INTO error_groups(
                        fingerprint,severity,environment,title,source_frame,count,first_seen,last_seen,
                        sample_message,sample_context,created_at,status,status_updated_at,
                        log_type,channel,origin,kind,external_source,external_id,external_url,assignee
                     ) VALUES(?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $fingerprint, $severity, self::SOURCE, $title, $identifier ?: null,
                        $firstSeen, $lastSeen, $description !== '' ? $description : $title, $context,
                        $firstSeen, $status, $lastSeen, self::SOURCE, $teamKey ?: null, self::SOURCE, 'issue',
                        self::SOURCE, $externalId, $url ?: null, $assignee,
                    ],
                );
                $db->execute(
                    'INSERT INTO issue_status_history(group_id,from_status,to_status,note) VALUES(?,NULL,?,?)',
                    [$groupId, $status, 'Imported from Linear (' . ($identifier ?: $externalId) . ').'],
                );
                $created = 1;
            } else {
                // Refresh the mirrored fields but leave `status` alone: local
                // workflow changes (and their write-back to Linear) are the
                // authority once an issue has been imported.
                $db->execute(
                    'UPDATE error_groups SET severity=?,title=?,source_frame=?,last_seen=?,
                        sample_message=?,sample_context=?,channel=?,external_url=?,assignee=?
                     WHERE id=?',
                    [
                        $severity, $title, $identifier ?: null, $lastSeen,
                        $description !== '' ? $description : $title, $context, $teamKey ?: null,
                        $url ?: null, $assignee, $groupId,
                    ],
                );
                $updated = 1;
            }

            $tagsCreated = $this->syncLabels($groupId, $issue['labels']['nodes'] ?? []);
            return ['created' => $created, 'updated' => $updated, 'tags_created' => $tagsCreated];
        });
    }

    /**
     * Ensure a tag exists for each Linear label and attach it to the issue.
     *
     * @param list<array<string,mixed>> $labels
     */
    private function syncLabels(int $groupId, array $labels): int
    {
        $dialect = Dialects::active();
        $insertTagSql = $dialect->insertIgnore('tags', 'name,color,icon', '?,?,?');
        $findTagSql = 'SELECT id FROM tags WHERE ' . $dialect->caseInsensitiveEquals('name');
        $assignSql = $dialect->insertIgnore('error_group_tags', 'group_id,tag_id,source', "?,?,'linear'");
        $created = 0;
        foreach ($labels as $label) {
            $name = trim((string) ($label['name'] ?? ''));
            if ($name === '' || strlen($name) > 80) {
                continue;
            }
            $color = $this->hexColor((string) ($label['color'] ?? ''));
            if ($this->db->execute($insertTagSql, [$name, $color, 'tag']) > 0) {
                $created++;
            }
            $tagId = (int) ($this->db->selectValue($findTagSql, [$name]) ?: 0);
            if ($tagId > 0) {
                $this->db->execute($assignSql, [$groupId, $tagId]);
            }
        }
        return $created;
    }

    private function client(): LinearClient
    {
        return $this->clientOverride ?? $this->settings->client();
    }

    private const STATUS_TO_STATE_TYPE = [
        'fixed' => 'completed',
        'wont_fix' => 'canceled',
        'in_progress' => 'started',
    ];

    private function severity(mixed $priority): string
    {
        return match ((int) $priority) {
            1 => 'CRITICAL', // Urgent
            2 => 'ERROR',    // High
            3 => 'WARNING',  // Medium
            4 => 'NOTICE',   // Low
            default => 'INFO', // No priority
        };
    }

    private function status(mixed $stateType): string
    {
        return match ((string) $stateType) {
            'completed' => 'fixed',
            'canceled' => 'wont_fix',
            'started' => 'in_progress',
            default => 'open', // backlog, unstarted, triage
        };
    }

    private function assigneeLabel(mixed $assignee): ?string
    {
        if (!is_array($assignee)) {
            return null;
        }
        $name = trim((string) ($assignee['name'] ?? ''));
        $email = trim((string) ($assignee['email'] ?? ''));
        $label = $name !== '' ? $name : $email;
        return $label !== '' ? $this->truncate($label, 200) : null;
    }

    private function hexColor(string $color): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : '#64748b';
    }

    private function timestamp(mixed $iso): string
    {
        $value = is_string($iso) ? strtotime($iso) : false;
        return gmdate('Y-m-d H:i:s', $value !== false ? $value : time());
    }

    private function truncate(string $value, int $limit): string
    {
        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }
}
