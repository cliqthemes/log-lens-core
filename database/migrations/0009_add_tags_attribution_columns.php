<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * Attributes tag creation/edits to the acting user (C-2), the same free-text
 * label convention as `issue_status_history.actor` — NULL means a
 * system/unattributed change (older rows, imports). Tags have no per-change
 * history table like issues do, so this tracks only the most recent
 * creator/editor rather than a full audit trail.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('tags', function (Blueprint $table): void {
            $table->text('created_by')->nullable();
            $table->text('updated_by')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('tags', function (Blueprint $table): void {
            $table->dropColumn('created_by');
            $table->dropColumn('updated_by');
        });
    }
};
