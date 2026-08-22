<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * Externally-sourced issues (e.g. Linear). `origin='linear'` rows carry the
 * upstream identifiers so they can be de-duplicated on re-sync and linked
 * back to their source system from the UI.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->text('external_source')->nullable();
            $table->text('external_id')->nullable();
            $table->text('external_url')->nullable();
            $table->text('assignee')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->dropColumn('external_source');
            $table->dropColumn('external_id');
            $table->dropColumn('external_url');
            $table->dropColumn('assignee');
        });
    }
};
