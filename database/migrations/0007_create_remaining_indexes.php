<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * Every index the schema needs beyond the ones created alongside their own
 * new columns (0004's `idx_occurrences_day`, 0005's three access-log
 * indexes) — covering the common query paths (recent sorting, workflow/
 * severity/module filters, connector/sync lookups, tag and alert joins).
 *
 * `mysqlPrefix()` bounds a handful of these to a prefix length: MySQL cannot
 * index an unbounded TEXT column without one (SQLite/Postgres ignore it —
 * neither has such a limit).
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->index(['last_seen DESC'], 'idx_groups_last_seen')->mysqlPrefix('last_seen', 19);
            $table->index(['severity'], 'idx_groups_severity')->mysqlPrefix('severity', 50);
            $table->index(['status'], 'idx_groups_status');
            $table->index(['log_type'], 'idx_groups_type');
            $table->index(['origin'], 'idx_groups_origin');
            $table->index(['kind'], 'idx_groups_kind');
            $table->index(['module_id'], 'idx_groups_module');
            $table->index(['external_source', 'external_id'], 'idx_groups_external')
                ->mysqlPrefix('external_source', 100)->mysqlPrefix('external_id', 100);
        });

        $schema->table('source_files', function (Blueprint $table): void {
            $table->index(['log_type'], 'idx_source_files_type');
            $table->index(['module_id'], 'idx_source_files_module');
            $table->index(['source_stream_id'], 'idx_source_files_stream');
        });

        $schema->table('occurrences', function (Blueprint $table): void {
            $table->index(['event_hash'], 'idx_occurrences_event_hash')->mysqlPrefix('event_hash', 191);
            $table->index(['group_id', 'occurred_at DESC'], 'idx_occurrences_group_time')->mysqlPrefix('occurred_at', 19);
            $table->index(['occurred_at DESC'], 'idx_occurrences_time')->mysqlPrefix('occurred_at', 19);
            $table->index(['release'], 'idx_occurrences_release')->mysqlPrefix('release', 191);
        });

        $schema->table('source_streams', function (Blueprint $table): void {
            $table->index(['connector_id', 'remote_path'], 'idx_streams_connector_path')->mysqlPrefix('remote_path', 300);
        });

        $schema->table('connector_sync_runs', function (Blueprint $table): void {
            $table->index(['connector_id', 'started_at DESC'], 'idx_sync_runs_connector_time');
        });

        $schema->table('issue_status_history', function (Blueprint $table): void {
            $table->index(['group_id', 'created_at DESC'], 'idx_status_history_group_time');
        });

        $schema->table('error_group_tags', function (Blueprint $table): void {
            $table->index(['tag_id'], 'idx_group_tags_tag');
        });

        $schema->table('alert_rules', function (Blueprint $table): void {
            $table->index(['enabled'], 'idx_alert_rules_enabled');
        });

        $schema->table('alert_events', function (Blueprint $table): void {
            $table->index(['rule_id', 'created_at DESC'], 'idx_alert_events_rule');
        });

        $schema->table('releases', function (Blueprint $table): void {
            $table->index(['deployed_at DESC'], 'idx_releases_deployed')->mysqlPrefix('deployed_at', 19);
        });

        $schema->table('source_maps', function (Blueprint $table): void {
            $table->index(['release', 'file'], 'idx_source_maps_lookup');
        });
    }

    public function down(Schema $schema): void
    {
        // Deliberately does NOT drop every index this migration created —
        // only the two that would otherwise block a later (lower-numbered)
        // migration's down() from dropping the column they cover:
        // idx_groups_external (external_source/external_id, dropped by
        // 0002's down()) and idx_occurrences_release (release, dropped by
        // 0003's down()). Migrator::rollback() processes migrations in
        // reverse-id order, so this one always runs before 0002/0003.
        //
        // Every other index here covers a column that is never dropped on
        // its own — it only ever goes away when 0001's down() drops the
        // whole table, taking the index with it — so leaving them in place
        // is harmless. Several of them (idx_groups_module,
        // idx_source_files_module, idx_source_files_stream,
        // idx_group_tags_tag, idx_streams_connector_path,
        // idx_sync_runs_connector_time, idx_alert_events_rule) also cover a
        // foreign-key column; MySQL/InnoDB refuses to drop an index a FK
        // constraint still needs ("Cannot drop index … needed in a foreign
        // key constraint"), so attempting to drop them here would fail on
        // that engine for no benefit.
        $schema->table('error_groups', function (Blueprint $table): void {
            $table->dropIndex('idx_groups_external');
        });
        $schema->table('occurrences', function (Blueprint $table): void {
            $table->dropIndex('idx_occurrences_release');
        });
    }
};
