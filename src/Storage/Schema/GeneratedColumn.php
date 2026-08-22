<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * The two generated-column shapes Log Lens's schema actually needs — a
 * substring (used for `occurred_day`) and a JSON-field extraction guarded
 * against non-JSON input (used for the four access-log fields parsed out of
 * `context_preview`). Each {@see Grammar} renders whichever engine-specific
 * expression produces the same result: SQLite's `json_valid()`/
 * `json_extract()`, Postgres's `safe_jsonb()` helper, MySQL's native
 * `JSON_VALID()`/`->>`.
 */
final class GeneratedColumn
{
    private function __construct(
        public readonly string $kind,
        public readonly string $sourceColumn,
        public readonly int $start = 0,
        public readonly int $length = 0,
        public readonly string $jsonPath = '',
        /** 'integer' | 'text' — only meaningful for kind === 'json' */
        public readonly string $castType = 'text',
        /**
         * MySQL only: the VARCHAR bound for a text-typed generated column
         * that will be indexed (MySQL cannot index a generated TEXT/BLOB
         * column without one). Null keeps it unbounded TEXT — fine for a
         * generated column that is never indexed. SQLite/Postgres ignore
         * this; both are unbounded TEXT regardless.
         */
        public readonly ?int $mysqlVarcharLength = null,
    ) {
    }

    public static function substring(string $sourceColumn, int $start, int $length): self
    {
        return new self('substring', $sourceColumn, start: $start, length: $length);
    }

    public static function jsonField(string $sourceColumn, string $jsonPath, string $castType, ?int $mysqlVarcharLength = null): self
    {
        return new self('json', $sourceColumn, jsonPath: $jsonPath, castType: $castType, mysqlVarcharLength: $mysqlVarcharLength);
    }
}
