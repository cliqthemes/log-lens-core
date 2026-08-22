<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * PostgreSQL DDL (opt-in via `LOG_LENS_DB_DRIVER=pgsql`).
 *
 * Assumes the shared `public.safe_jsonb(text)` helper already exists — an
 * `IMMUTABLE` function standing in for SQLite's `json_valid()` (Postgres has
 * no equivalent; `->>`/`::jsonb` on invalid JSON simply throws). Created once
 * per physical database by {@see \LogLens\Storage\PostgresDriver} before
 * migrations run, not per-schema — it is schema-neutral and safe to share.
 */
final class PostgresGrammar extends AbstractGrammar
{
    public function name(): string
    {
        return 'pgsql';
    }

    protected function currentTimestamp(): string
    {
        return "to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')";
    }

    protected function extraStatementsForColumn(string $table, ColumnDefinition $column): array
    {
        if (!$column->caseInsensitiveUnique) {
            return [];
        }
        // No column-level case-insensitive collation equivalent to SQLite's
        // COLLATE NOCASE — enforce it with a functional index instead.
        return ["CREATE UNIQUE INDEX ux_{$table}_{$column->name} ON {$table} (LOWER({$this->quoteIdentifier($column->name)}));"];
    }

    protected function columnLine(ColumnDefinition $column): string
    {
        $name = $this->quoteIdentifier($column->name);

        if ($column->type === 'increments') {
            return "{$name} INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY";
        }

        if ($column->generated !== null) {
            return "{$name} " . $this->generatedColumnSql($column);
        }

        $type = match ($column->type) {
            'integer' => 'INTEGER',
            'real' => 'REAL',
            default => 'TEXT', // 'text' and 'string' — Postgres TEXT has no meaningful length limit
        };

        $sql = "{$name} {$type}";
        $sql .= $column->nullable ? '' : ' NOT NULL';
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->resolveDefault($column);
        }
        // Case-insensitive uniqueness is enforced by the functional index in
        // extraStatementsForColumn(), not an inline constraint here.
        if ($column->unique && !$column->caseInsensitiveUnique) {
            $sql .= ' UNIQUE';
        }
        return $sql;
    }

    private function generatedColumnSql(ColumnDefinition $column): string
    {
        $generated = $column->generated;
        $type = $column->type === 'integer' ? 'INTEGER' : 'TEXT';
        $expr = match ($generated->kind) {
            'substring' => "substr({$generated->sourceColumn},{$generated->start},{$generated->length})",
            'json' => $generated->castType === 'integer'
                ? "(public.safe_jsonb({$generated->sourceColumn})->>'{$generated->jsonPath}')::integer"
                : "public.safe_jsonb({$generated->sourceColumn})->>'{$generated->jsonPath}'",
        };
        // Postgres has no VIRTUAL generated column (pre-18); STORED is the
        // only option through the versions this driver targets.
        return "{$type} GENERATED ALWAYS AS ({$expr}) STORED";
    }
}
