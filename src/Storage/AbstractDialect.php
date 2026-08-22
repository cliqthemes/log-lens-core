<?php
declare(strict_types=1);

namespace LogLens\Storage;

/**
 * Shared Dialect behaviour, derived from a few per-engine primitives
 * (insert-ignore prefix/suffix, now, and — for MySQL — the upsert form).
 */
abstract class AbstractDialect implements Dialect
{
    abstract public function name(): string;

    abstract public function now(int $offsetSeconds = 0): string;

    abstract public function shiftDays(string $dayExpr, int $days): string;

    /** The verb that opens an insert-ignore, e.g. `INSERT OR IGNORE INTO`. */
    abstract protected function insertIgnorePrefix(): string;

    /** The clause that closes an insert-ignore, e.g. ` ON CONFLICT DO NOTHING`. */
    abstract protected function insertIgnoreSuffix(): string;

    public function insertIgnore(string $table, string $columns, string $placeholders): string
    {
        return "{$this->insertIgnorePrefix()} {$table} ({$columns}) VALUES ({$placeholders}){$this->insertIgnoreSuffix()}";
    }

    public function insertIgnoreSelect(string $table, string $columns, string $select): string
    {
        return "{$this->insertIgnorePrefix()} {$table} ({$columns}) {$select}{$this->insertIgnoreSuffix()}";
    }

    public function upsert(string $table, string $columns, string $values, array $conflictKeys, array $updateColumns): string
    {
        // An update entry is either a bare column ("value" → value=excluded.value)
        // or a full assignment kept verbatim ("updated_at=CURRENT_TIMESTAMP").
        $set = implode(', ', array_map(
            static fn (string $c): string => str_contains($c, '=') ? $c : "{$c}=excluded.{$c}",
            $updateColumns,
        ));
        $keys = implode(',', $conflictKeys);
        return "INSERT INTO {$table} ({$columns}) VALUES ({$values}) ON CONFLICT({$keys}) DO UPDATE SET {$set}";
    }

    public function keyValueUpsert(string $table = 'app_settings'): string
    {
        $key = $this->quoteIdentifier('key');
        return $this->upsert(
            $table,
            "{$key},value,updated_at",
            '?,?,CURRENT_TIMESTAMP',
            [$key],
            ['value', 'updated_at=CURRENT_TIMESTAMP'],
        );
    }

    public function keyValueLookup(string $table = 'app_settings'): string
    {
        return "SELECT value FROM {$table} WHERE {$this->quoteIdentifier('key')}=?";
    }

    // The defaults below assume "already case-insensitive by default" — true for
    // SQLite (ASCII-insensitive LIKE; NOCASE-collated columns) and for MySQL
    // (the schema declares `name`/`slug` columns with a case-insensitive
    // collation, C-1). Only Postgres, which has neither, overrides them.

    public function caseInsensitiveLike(string $column, string $placeholder = '?'): string
    {
        return "{$column} LIKE {$placeholder}";
    }

    public function caseInsensitiveEquals(string $column, string $placeholder = '?'): string
    {
        return "{$column}={$placeholder}";
    }

    public function caseInsensitiveOrder(string $column): string
    {
        return $column;
    }

    public function castToText(string $expr): string
    {
        return "CAST({$expr} AS TEXT)";
    }

    // Postgres and MySQL both have real LEAST()/GREATEST() scalar functions;
    // only SQLite lacks them and instead overloads MIN()/MAX() to behave as
    // scalar when given 2+ arguments (SqliteDialect overrides these two).

    public function least(string $a, string $b): string
    {
        return "LEAST({$a}, {$b})";
    }

    public function greatest(string $a, string $b): string
    {
        return "GREATEST({$a}, {$b})";
    }

    public function jsonObject(array $pairs): string
    {
        $args = [];
        foreach ($pairs as $key => $expr) {
            $args[] = "'{$key}'," . $expr;
        }
        return 'json_object(' . implode(',', $args) . ')';
    }

    public function jsonArrayAgg(string $expr): string
    {
        return "json_group_array({$expr})";
    }

    /** ANSI double-quoting — the default; MySQL overrides with backticks. */
    public function quoteIdentifier(string $name): string
    {
        return '"' . $name . '"';
    }
}
