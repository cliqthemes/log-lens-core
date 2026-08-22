<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * Marks a string as a raw SQL expression rather than a literal value to be
 * quoted — e.g. a column default that must be evaluated by the engine
 * (`Grammar::currentTimestamp()`) rather than stored as text. Mirrors
 * `DB::raw()` in more featureful query builders.
 */
final class Expr
{
    public function __construct(public readonly string $sql)
    {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
