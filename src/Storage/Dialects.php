<?php
declare(strict_types=1);

namespace LogLens\Storage;

/**
 * The SQL dialect for the active database engine.
 *
 * A thin convenience wrapper over {@see Drivers}: `Driver` is the stateful
 * "connect/migrate/tenancy" concern (see its docblock for the split), and a
 * Dialect is just one thing a Driver hands out. This lets the ~dozen services
 * that emit engine-divergent SQL depend on the small, stateless piece
 * directly, without pulling in connection/migration machinery.
 */
final class Dialects
{
    public static function active(): Dialect
    {
        return Drivers::active()->dialect();
    }

    /** Forget the memoized driver/dialect (config changes / test isolation). */
    public static function reset(): void
    {
        Drivers::reset();
    }
}
