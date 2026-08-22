<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * A table-level index, unique constraint, or composite primary key on a
 * {@see Blueprint}. Single-column indexes/uniques are usually declared
 * inline on the column instead ({@see ColumnDefinition::unique()}); this is
 * for composite keys and named indexes.
 */
final class IndexDefinition
{
    /** A raw SQL predicate (e.g. `access_status IS NOT NULL`) for a partial index. Postgres/SQLite honor it; MySQL (no partial-index support) silently ignores it and indexes the whole table — see MysqlGrammar. */
    public ?string $where = null;

    /**
     * MySQL-only prefix lengths, keyed by column, for any member that is
     * still unbounded TEXT (MySQL cannot key/index one without an explicit
     * length) — ignored by SQLite/Postgres, which have no such limit.
     *
     * @var array<string,int>
     */
    public array $mysqlPrefixLengths = [];

    public function __construct(
        /** 'unique' | 'index' | 'primary' */
        public readonly string $kind,
        /** @var list<string> */
        public readonly array $columns,
        public readonly string $name,
    ) {
    }

    public function where(string $predicate): self
    {
        $this->where = $predicate;
        return $this;
    }

    public function mysqlPrefix(string $column, int $length): self
    {
        $this->mysqlPrefixLengths[$column] = $length;
        return $this;
    }
}
