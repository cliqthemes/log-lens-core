<?php
declare(strict_types=1);

namespace LogLens\Storage;

/**
 * The small set of SQL fragments that differ between database engines (C-1).
 *
 * Everything else in the codebase is portable ANSI SQL; only these constructs
 * diverge (insert-ignore, upsert, and the current-timestamp expression). A
 * driver supplies its own Dialect so the shared repositories build engine
 * correct SQL without knowing which engine they run on. SQLite is the default;
 * Postgres/MySQL are opt-in.
 */
interface Dialect
{
    /** Engine key: 'sqlite' | 'pgsql' | 'mysql'. */
    public function name(): string;

    /**
     * A current-timestamp expression, optionally offset by whole seconds
     * (negative = in the past), for use inside SQL.
     */
    public function now(int $offsetSeconds = 0): string;

    /**
     * A full INSERT that silently ignores a unique/primary-key conflict.
     *
     * @param string $columns      comma-separated column list
     * @param string $placeholders comma-separated value list (`?` or literals)
     */
    public function insertIgnore(string $table, string $columns, string $placeholders): string;

    /** Like insertIgnore, but the rows come from a SELECT rather than VALUES. */
    public function insertIgnoreSelect(string $table, string $columns, string $select): string;

    /**
     * A full INSERT that, on a conflict against $conflictKeys, updates
     * $updateColumns from the incoming row.
     *
     * @param string       $columns       comma-separated column list
     * @param string       $values        comma-separated value list (`?` or literals)
     * @param list<string> $conflictKeys  the unique/PK columns to match on
     * @param list<string> $updateColumns each entry is a bare column name
     *   (set from the incoming row) or a full `col=EXPR` assignment kept verbatim
     */
    public function upsert(string $table, string $columns, string $values, array $conflictKeys, array $updateColumns): string;

    /**
     * The app's ubiquitous key/value upsert: set `value` (and touch
     * `updated_at`) on a `key` conflict. Centralized because it appears in ~10
     * services — and because `key` is a reserved word in MySQL, needing the
     * same {@see quoteIdentifier()} treatment as `release` (see there).
     */
    public function keyValueUpsert(string $table = 'app_settings'): string;

    /**
     * The read-side counterpart of {@see keyValueUpsert()}: `SELECT value
     * FROM {$table} WHERE key=?`, with `key` quoted. Just as ubiquitous —
     * centralized for the same reason.
     */
    public function keyValueLookup(string $table = 'app_settings'): string;

    /**
     * A LIKE comparison that matches case-insensitively regardless of engine.
     * SQLite's LIKE is ASCII-case-insensitive by default and MySQL's `name`/
     * `slug` columns are declared with a case-insensitive collation (C-1), so
     * both just use LIKE; Postgres LIKE is always case-sensitive and needs
     * ILIKE.
     */
    public function caseInsensitiveLike(string $column, string $placeholder = '?'): string;

    /**
     * An `=` comparison that ignores case regardless of engine — the query-time
     * counterpart of the case-insensitive unique columns (`modules.name`,
     * `tags.name`, …) declared by the schema.
     */
    public function caseInsensitiveEquals(string $column, string $placeholder = '?'): string;

    /** An ORDER BY expression that sorts case-insensitively. */
    public function caseInsensitiveOrder(string $column): string;

    /** Cast a SQL expression to a text/string type — MySQL's cast target is CHAR, not TEXT. */
    public function castToText(string $expr): string;

    /**
     * Quote a bare identifier (column/table name) so it is safe to use even
     * when it collides with a reserved word on some engine — e.g. `release`,
     * a column name that is also a MySQL keyword (`RELEASE SAVEPOINT`) and
     * fails unquoted in both DDL and ordinary queries there, but is not
     * reserved in SQLite or Postgres. Double-quoting is a harmless no-op on
     * those two; MySQL needs backticks instead.
     */
    public function quoteIdentifier(string $name): string;

    /**
     * The lesser of two SQL expressions (e.g. two columns, or a column and a
     * placeholder) — the scalar (not aggregate) form. SQLite overloads `MIN`
     * for this; Postgres and MySQL both reject a multi-argument `MIN()` (it's
     * aggregate-only on those two) and require `LEAST()` instead.
     */
    public function least(string $a, string $b): string;

    /** The greater of two SQL expressions — see {@see least()}. */
    public function greatest(string $a, string $b): string;

    /**
     * A stored `YYYY-MM-DD` day expression shifted by whole days, as text.
     *
     * There is no portable spelling to share here: `date(x,'-29 days')` is
     * SQLite's modifier overload, Postgres's `date()` takes exactly one
     * argument, and MySQL has no such overload at all — so the entire
     * expression differs per engine, not just a function name. The result is
     * text on every engine because what it is compared against
     * (`occurrences.occurred_day`) stores the day as the leading ten
     * characters of the timestamp, not as a date type.
     */
    public function shiftDays(string $dayExpr, int $days): string;

    /**
     * A JSON object literal built from ordered `key => SQL expression` pairs
     * (e.g. `['id' => 't.id', 'name' => 't.name']`).
     *
     * @param array<string,string> $pairs
     */
    public function jsonObject(array $pairs): string;

    /**
     * Aggregate a per-row JSON expression (typically {@see jsonObject}) into a
     * JSON array across the rows of a (usually correlated) subquery. Yields
     * NULL, not an empty array, when no rows match — every caller already
     * treats a NULL/empty result the same way.
     */
    public function jsonArrayAgg(string $expr): string;
}
