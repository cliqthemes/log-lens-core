<?php
declare(strict_types=1);

namespace LogLens\Services;

use LogLens\Storage\Dialects;

use LogLens\Config;
use LogLens\Storage\Connection;
use LogLens\Storage\PdoConnection;
use PDO;

/**
 * Bounds database growth by pruning old indexed occurrences. This is the
 * database-side complement to {@see ProcessedRetentionService} (which prunes
 * archived log *files*) and to the ingest rate limiter (which bounds inflow).
 *
 * Two independent rules, both off by default:
 *   - age:   delete occurrences older than N days;
 *   - count: keep only the newest N occurrences per issue.
 *
 * Pruning only removes occurrences; the caller's {@see IssueRepository::
 * refreshAggregates()} then recomputes counts and drops any ingested group left
 * empty (manual and linear issues, which have no occurrences, are untouched).
 *
 * Runs are self-throttled via a stored timestamp so it can be called safely on
 * every import/ingest without pruning on every single write.
 */
final class OccurrenceRetentionService
{
    private const LAST_RUN_KEY = 'retention.occurrences_last_run';

    private readonly Connection $db;

    public function __construct(
        Connection|PDO $db,
        private readonly ?int $maxAgeDays = null,
        private readonly ?int $maxPerGroup = null,
        private readonly ?int $intervalSeconds = null,
        private readonly ?int $now = null,
    ) {
        $this->db = $db instanceof Connection ? $db : new PdoConnection($db, Dialects::active());
    }

    /**
     * @return array{enabled:bool,deleted:int,skipped?:bool}
     */
    public function prune(bool $throttle = true): array
    {
        $maxAge = $this->maxAgeDays ?? Config::int('retention.occurrences_max_age_days', 0, 0);
        $maxPerGroup = $this->maxPerGroup ?? Config::int('retention.max_occurrences_per_group', 0, 0);
        if ($maxAge <= 0 && $maxPerGroup <= 0) {
            return ['enabled' => false, 'deleted' => 0];
        }
        if ($throttle && !$this->due()) {
            return ['enabled' => true, 'skipped' => true, 'deleted' => 0];
        }

        $deleted = 0;
        if ($maxAge > 0) {
            $cutoff = Dialects::active()->now(-$maxAge * 86400);
            $deleted += $this->db->execute("DELETE FROM occurrences WHERE occurred_at < {$cutoff}");
        }
        if ($maxPerGroup > 0) {
            // Keep the newest N per group; delete the rest. Window functions
            // require SQLite ≥ 3.25 (bundled with modern PHP).
            $deleted += $this->db->execute(
                "DELETE FROM occurrences WHERE id IN (
                    SELECT id FROM (
                        SELECT id, ROW_NUMBER() OVER (
                            PARTITION BY group_id ORDER BY occurred_at DESC, id DESC
                        ) rn FROM occurrences
                    ) WHERE rn > " . $maxPerGroup . "
                 )"
            );
        }

        $this->markRun();
        return ['enabled' => true, 'deleted' => $deleted];
    }

    private function due(): bool
    {
        $interval = $this->intervalSeconds ?? Config::int('retention.occurrences_interval_seconds', 900, 0);
        if ($interval <= 0) {
            return true;
        }
        $last = (int) ($this->db->selectValue(Dialects::active()->keyValueLookup(), [self::LAST_RUN_KEY]) ?? 0);
        return ($this->now ?? time()) - $last >= $interval;
    }

    private function markRun(): void
    {
        $this->db->execute(Dialects::active()->keyValueUpsert(), [self::LAST_RUN_KEY, (string) ($this->now ?? time())]);
    }
}
