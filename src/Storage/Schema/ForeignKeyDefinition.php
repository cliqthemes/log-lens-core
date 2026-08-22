<?php
declare(strict_types=1);

namespace LogLens\Storage\Schema;

/**
 * A foreign key on a {@see Blueprint}, built fluently:
 * `$table->foreign('module_id')->references('id')->on('modules')->onDelete('set null')`.
 */
final class ForeignKeyDefinition
{
    public string $referencesColumn = 'id';
    public string $onTable = '';
    /** 'cascade' | 'set null' | 'restrict' | 'no action' */
    public string $onDeleteAction = 'no action';

    public function __construct(public readonly string $column)
    {
    }

    public function references(string $column): self
    {
        $this->referencesColumn = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->onTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDeleteAction = $action;
        return $this;
    }
}
