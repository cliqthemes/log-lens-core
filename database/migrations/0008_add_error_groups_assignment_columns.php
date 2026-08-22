<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * Assigns an issue to a person (C-2). Log Lens has no user table of its own —
 * a Laravel host owns its users, and standalone has only the local owner (see
 * Identity\Actor) — so this stores an opaque id/label pair supplied by the
 * assigning system rather than a foreign key: `assigned_to_id` is whatever the
 * host's `log-lens.assignable-users` callable returned as that user's id;
 * `assigned_to_label` is their display name, shown without a lookup. Distinct
 * from the existing `assignee` column, which is a read-only mirror of the
 * *Linear* issue's assignee (Releases/Linear plugin) and is never written by
 * Log Lens itself.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->text('assigned_to_id')->nullable();
            $table->text('assigned_to_label')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->dropColumn('assigned_to_id');
            $table->dropColumn('assigned_to_label');
        });
    }
};
