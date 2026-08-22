<?php
declare(strict_types=1);

namespace LogLens\Storage;

/** SQLite SQL fragments — the default, zero-config engine. */
final class SqliteDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function now(int $offsetSeconds = 0): string
    {
        if ($offsetSeconds === 0) {
            return "datetime('now')";
        }
        $sign = $offsetSeconds < 0 ? '' : '+';
        return "datetime('now','{$sign}{$offsetSeconds} seconds')";
    }

    public function shiftDays(string $dayExpr, int $days): string
    {
        $sign = $days < 0 ? '' : '+';
        return "date({$dayExpr},'{$sign}{$days} days')";
    }

    protected function insertIgnorePrefix(): string
    {
        return 'INSERT OR IGNORE INTO';
    }

    protected function insertIgnoreSuffix(): string
    {
        return '';
    }

    // SQLite has no LEAST()/GREATEST() — MIN()/MAX() double as the scalar
    // form when given 2+ arguments (unlike Postgres/MySQL, where MIN/MAX are
    // aggregate-only — see AbstractDialect's default for those two).

    public function least(string $a, string $b): string
    {
        return "MIN({$a}, {$b})";
    }

    public function greatest(string $a, string $b): string
    {
        return "MAX({$a}, {$b})";
    }
}
