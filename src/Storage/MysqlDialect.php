<?php
declare(strict_types=1);

namespace LogLens\Storage;

/** MySQL/MariaDB SQL fragments (opt-in via LOG_LENS_DB_DRIVER=mysql). */
final class MysqlDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function now(int $offsetSeconds = 0): string
    {
        return $offsetSeconds === 0 ? 'NOW()' : "(NOW() + INTERVAL {$offsetSeconds} SECOND)";
    }

    public function shiftDays(string $dayExpr, int $days): string
    {
        return "DATE_FORMAT(DATE_ADD({$dayExpr}, INTERVAL {$days} DAY), '%Y-%m-%d')";
    }

    protected function insertIgnorePrefix(): string
    {
        return 'INSERT IGNORE INTO';
    }

    protected function insertIgnoreSuffix(): string
    {
        return '';
    }

    /** MySQL has no ON CONFLICT; it uses ON DUPLICATE KEY UPDATE col=VALUES(col). */
    public function upsert(string $table, string $columns, string $values, array $conflictKeys, array $updateColumns): string
    {
        $set = implode(', ', array_map(
            static fn (string $c): string => str_contains($c, '=') ? $c : "{$c}=VALUES({$c})",
            $updateColumns,
        ));
        return "INSERT INTO {$table} ({$columns}) VALUES ({$values}) ON DUPLICATE KEY UPDATE {$set}";
    }

    // caseInsensitiveLike/Equals/Order are inherited plain: the schema declares
    // the affected columns (`modules.name`, `tags.name`, …) with a
    // case-insensitive collation (utf8mb4_0900_ai_ci), so `=`/LIKE/ORDER BY are
    // already case-insensitive without help from the query.

    public function castToText(string $expr): string
    {
        return "CAST({$expr} AS CHAR)";
    }

    public function jsonArrayAgg(string $expr): string
    {
        return "JSON_ARRAYAGG({$expr})";
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . $name . '`';
    }
}
