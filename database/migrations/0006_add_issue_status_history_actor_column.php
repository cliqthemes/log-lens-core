<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/** Attributes a workflow status change to the acting user (C-2). NULL means a system/unattributed change (older rows, imports, Linear). */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('issue_status_history', function (Blueprint $table): void {
            $table->text('actor')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('issue_status_history', function (Blueprint $table): void {
            $table->dropColumn('actor');
        });
    }
};
