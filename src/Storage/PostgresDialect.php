<?php
declare(strict_types=1);

namespace LogLens\Storage;

/** PostgreSQL SQL fragments (opt-in via LOG_LENS_DB_DRIVER=pgsql). */
final class PostgresDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    /**
     * Every timestamp column in the schema is TEXT, in SQLite's exact
     * 'YYYY-MM-DD HH:MM:SS' UTC format (see PostgresDriver) — comparing a raw
     * `NOW()` (timestamptz) against one throws ("operator does not exist:
     * text >= timestamp with time zone"), since Postgres does not implicitly
     * cast between them. Formatting to that same string here keeps every
     * `WHERE occurred_at >= {$dialect->now(...)}` comparison lexicographic,
     * exactly like SQLite's.
     */
    public function now(int $offsetSeconds = 0): string
    {
        $instant = $offsetSeconds === 0 ? 'NOW()' : "NOW() + make_interval(secs => {$offsetSeconds})";
        return "to_char(({$instant}) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')";
    }

    public function shiftDays(string $dayExpr, int $days): string
    {
        return "to_char(({$dayExpr})::date + make_interval(days => {$days}), 'YYYY-MM-DD')";
    }

    protected function insertIgnorePrefix(): string
    {
        return 'INSERT INTO';
    }

    protected function insertIgnoreSuffix(): string
    {
        return ' ON CONFLICT DO NOTHING';
    }
    // upsert() is inherited: Postgres shares SQLite's ON CONFLICT … DO UPDATE syntax.

    // Postgres has neither SQLite's NOCASE column collation nor MySQL's
    // case-insensitive default collation, so — unlike the other two engines —
    // it cannot inherit AbstractDialect's plain-comparison defaults here. The
    // schema backs these with `CREATE UNIQUE INDEX ... (LOWER(column))`
    // instead of a plain UNIQUE constraint, so these must match by using
    // LOWER() too.

    public function caseInsensitiveLike(string $column, string $placeholder = '?'): string
    {
        return "{$column} ILIKE {$placeholder}";
    }

    public function caseInsensitiveEquals(string $column, string $placeholder = '?'): string
    {
        return "LOWER({$column})=LOWER({$placeholder})";
    }

    public function caseInsensitiveOrder(string $column): string
    {
        return "LOWER({$column})";
    }

    public function jsonObject(array $pairs): string
    {
        $args = [];
        foreach ($pairs as $key => $expr) {
            $args[] = "'{$key}'," . $expr;
        }
        return 'json_build_object(' . implode(',', $args) . ')';
    }

    public function jsonArrayAgg(string $expr): string
    {
        return "json_agg({$expr})";
    }
}
