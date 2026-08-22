<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * Compiles an engine-neutral {@see Blueprint} into that engine's DDL — Log
 * Lens's answer to Laravel's per-engine schema Grammar. One implementation
 * per engine: {@see SqliteGrammar}, {@see PostgresGrammar}, {@see MysqlGrammar}.
 *
 * Every method returns a list of SQL statements to run **in order** — a
 * table often needs more than one (the `CREATE TABLE` itself, then a
 * functional unique index for a case-insensitive column on Postgres, for
 * instance).
 */
interface Grammar
{
    /** Engine key: 'sqlite' | 'pgsql' | 'mysql'. */
    public function name(): string;

    /** A brand-new table: CREATE TABLE plus any indexes/foreign keys declared on the same Blueprint. */
    public function compileCreate(Blueprint $blueprint): array;

    /** New columns added to an existing table (a later migration's Schema::table() call). */
    public function compileAddColumns(Blueprint $blueprint): array;

    /** New indexes added to an existing table, independent of any new columns. */
    public function compileAddIndexes(Blueprint $blueprint): array;

    /** Columns removed from an existing table — the down() side of an add-columns migration. */
    public function compileDropColumns(Blueprint $blueprint): array;

    /**
     * Indexes removed from an existing table — the down() side of an
     * add-indexes migration. Always compile these before
     * {@see compileDropColumns()} in the same down(): the engine rejects
     * dropping a column an index still references.
     */
    public function compileDropIndexes(Blueprint $blueprint): array;

    public function compileDropTableIfExists(string $table): string;

    /** Quote a bare identifier — protects column names that collide with a reserved word on some engine (e.g. `release`, `key`). */
    public function quoteIdentifier(string $name): string;

    /**
     * Whether this engine's CREATE INDEX honors a WHERE predicate (a partial
     * index). MySQL does not — {@see MysqlGrammar} indexes the whole table
     * instead when an IndexDefinition carries one.
     */
    public function supportsPartialIndex(): bool;
}
