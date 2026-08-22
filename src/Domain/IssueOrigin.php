<?php
declare(strict_types=1);

namespace LogLens\Domain;

/**
 * The `error_groups.origin` vocabulary and the one rule that matters across the
 * codebase: which origins are backed by occurrences.
 *
 * `error_groups` is a shared table (parsed logs, HTTP-pushed events, manual
 * issues, and Linear issues all live here). Only occurrence-backed origins may
 * have their aggregates recomputed from occurrences or be pruned when they have
 * none — manual and Linear issues have no occurrences by design and must never
 * be counted to zero or deleted by that path. Duplicating this rule inline once
 * caused a data-loss bug (`origin <> 'manual'` wiping Linear issues), so it is
 * defined here exactly once (H-6).
 */
final class IssueOrigin
{
    /** Parsed logs and HTTP-pushed events: rows backed by rows in `occurrences`. */
    public const INGESTED = 'ingested';
    /** Operator-created issues (feature requests, tasks): no occurrences. */
    public const MANUAL = 'manual';
    /** Issues pulled from Linear: no occurrences. */
    public const LINEAR = 'linear';

    /** Origins whose `error_groups` rows are backed by occurrences. */
    public const OCCURRENCE_BACKED = [self::INGESTED];

    private function __construct()
    {
    }

    /**
     * Whether rows of $origin carry occurrences — i.e. whether they may be
     * aggregate-recomputed from, or pruned by the absence of, occurrences.
     */
    public static function hasOccurrences(string $origin): bool
    {
        return in_array($origin, self::OCCURRENCE_BACKED, true);
    }

    /**
     * A SQL boolean predicate selecting occurrence-backed rows for $column.
     * Use this instead of hardcoding `origin='ingested'` so the rule cannot
     * drift between call sites.
     */
    public static function occurrenceBackedSql(string $column = 'origin'): string
    {
        $quoted = array_map(static fn (string $origin): string => "'" . $origin . "'", self::OCCURRENCE_BACKED);
        return count($quoted) === 1
            ? $column . ' = ' . $quoted[0]
            : $column . ' IN (' . implode(', ', $quoted) . ')';
    }
}
