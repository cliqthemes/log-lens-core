<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * One column in a {@see Blueprint}. A plain fluent value object — `Grammar`
 * implementations read its public properties directly to compile SQL; it has
 * no behavior of its own beyond the chainable modifiers.
 */
final class ColumnDefinition
{
    public bool $nullable = false;
    public bool $unique = false;
    /** Case-insensitive uniqueness (`modules.name`, `tags.name`, …) — each Grammar implements this however its engine can (COLLATE NOCASE / functional index / collation). */
    public bool $caseInsensitiveUnique = false;
    public string|int|float|Expr|null $default = null;
    public bool $hasDefault = false;
    /** For `string` columns: the bound MySQL needs to key/default it; ignored by SQLite/Postgres (always TEXT there). */
    public int $length = 191;
    /** Generated-column spec, set by generatedSubstring()/generatedJson(); null for an ordinary column. */
    public ?GeneratedColumn $generated = null;

    public function __construct(
        public readonly string $name,
        /** 'increments' | 'integer' | 'real' | 'text' | 'string' */
        public readonly string $type,
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    public function default(string|int|float|Expr $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /** The column's default is "now", in this app's canonical per-engine timestamp expression (see Grammar::currentTimestamp()). */
    public function useCurrent(): self
    {
        $this->hasDefault = true;
        $this->default = new Expr('__CURRENT_TIMESTAMP__'); // resolved by each Grammar at compile time
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function caseInsensitiveUnique(): self
    {
        $this->caseInsensitiveUnique = true;
        $this->unique = true;
        return $this;
    }
}
