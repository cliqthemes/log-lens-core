<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * One discrete, engine-neutral schema change. A migration file under
 * `database/migrations/` returns an instance of an anonymous class extending
 * this (Laravel 8+ style — no class-name bookkeeping needed).
 *
 * `down()` defaults to a no-op: most of this schema's early migrations
 * predate the Migrator itself (see {@see Migrator}'s legacy-install bridge)
 * and are never actually rolled back in practice; write a real `down()` for
 * any migration added from here on where reverting it is meaningful.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
        // Intentionally empty by default.
    }
}
