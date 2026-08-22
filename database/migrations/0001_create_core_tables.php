<?php
declare(strict_types=1);

use LogLens\Storage\Schema\Blueprint;
use LogLens\Storage\Schema\Migration;
use LogLens\Storage\Schema\Schema;

/**
 * The baseline schema: all 17 core tables, as a fresh install creates them
 * today (some columns that shipped in later releases — e.g.
 * `source_files.module_id`, `error_groups.status`/`origin`/`kind` — are
 * folded in here rather than their own migration, since a brand-new database
 * has always gotten them from this same baseline; see the Migrator's
 * legacy-install bridge for how an existing database already at this state
 * is recognized instead of re-run).
 *
 * Table creation order matters on Postgres/MySQL (a foreign key's target
 * table must already exist); this is the same order the schema has always
 * used, and it is already a valid dependency order.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->caseInsensitiveUnique();
            $table->string('slug')->caseInsensitiveUnique();
            $table->string('color')->default('#6366f1');
            $table->timestamp('created_at')->useCurrent();
        });

        $schema->create('connectors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->caseInsensitiveUnique();
            $table->text('type');
            $table->integer('enabled')->default(1);
            $table->integer('module_id')->nullable();
            $table->text('config_json'); // app always supplies this explicitly on INSERT — see MysqlGrammar
            $table->text('last_synced_at')->nullable();
            $table->text('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('set null');
        });

        $schema->create('source_streams', function (Blueprint $table): void {
            $table->id();
            $table->integer('connector_id');
            $table->integer('module_id')->nullable();
            $table->string('remote_path', 600); // bounded: participates in the composite UNIQUE below (MySQL cannot key an unbounded TEXT)
            $table->text('remote_identity');
            $table->integer('generation')->default(1);
            $table->string('local_path', 600)->unique();
            $table->integer('source_file_id')->nullable(); // no FK: created before source_files exists
            $table->integer('remote_size')->default(0);
            $table->integer('fetched_offset')->default(0);
            $table->integer('remote_modified_at')->nullable();
            $table->text('prefix_hash')->nullable();
            $table->text('last_synced_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['connector_id', 'remote_path', 'generation'], 'ux_source_streams_connector_remote_generation');
            $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('set null');
        });

        $schema->create('connector_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->integer('connector_id');
            $table->text('status');
            $table->integer('files_discovered')->default(0);
            $table->integer('files_completed')->default(0);
            $table->integer('files_updated')->default(0);
            $table->integer('bytes_total')->default(0);
            $table->integer('bytes_fetched')->default(0);
            $table->integer('events_indexed')->default(0);
            $table->text('current_file')->nullable();
            $table->text('expected_snapshot')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->text('finished_at')->nullable();
            $table->foreign('connector_id')->references('id')->on('connectors')->onDelete('cascade');
        });

        $schema->create('source_files', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 600)->unique();
            $table->integer('size')->default(0);
            $table->integer('modified_at')->default(0);
            $table->integer('last_offset')->default(0);
            $table->text('imported_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->integer('parser_version')->default(1);
            $table->string('log_type', 30)->default('laravel');
            $table->text('channel')->nullable();
            $table->integer('module_id')->nullable();
            $table->integer('source_stream_id')->nullable();
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('set null');
            $table->foreign('source_stream_id')->references('id')->on('source_streams')->onDelete('set null');
        });

        $schema->create('error_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->text('severity');
            $table->text('environment')->nullable();
            $table->text('title');
            $table->text('exception_class')->nullable();
            $table->text('source_frame')->nullable();
            $table->integer('count')->default(0);
            $table->text('first_seen');
            $table->text('last_seen');
            $table->text('sample_message')->nullable();
            $table->text('sample_stack')->nullable();
            $table->text('sample_context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->string('status', 30)->default('open');
            $table->text('status_updated_at')->nullable();
            $table->string('log_type', 30)->default('laravel');
            $table->text('channel')->nullable();
            $table->string('origin', 30)->default('ingested');
            $table->string('kind', 30)->default('error');
            $table->integer('module_id')->nullable();
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('set null');
        });

        $schema->create('occurrences', function (Blueprint $table): void {
            $table->id();
            $table->integer('group_id');
            $table->integer('source_file_id');
            $table->text('exact_fingerprint');
            $table->text('occurred_at');
            $table->text('severity');
            $table->text('environment')->nullable();
            $table->integer('byte_start');
            $table->integer('byte_end');
            $table->text('context_preview')->nullable();
            $table->text('event_hash')->nullable();
            $table->unique(['source_file_id', 'byte_start'], 'ux_occurrences_source_file_byte_start');
            $table->foreign('group_id')->references('id')->on('error_groups')->onDelete('cascade');
            $table->foreign('source_file_id')->references('id')->on('source_files')->onDelete('cascade');
        });

        $schema->create('issue_status_history', function (Blueprint $table): void {
            $table->id();
            $table->integer('group_id');
            $table->text('from_status')->nullable();
            $table->text('to_status');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('group_id')->references('id')->on('error_groups')->onDelete('cascade');
        });

        $schema->create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->caseInsensitiveUnique();
            $table->string('color')->default('#64748b');
            $table->string('icon', 60)->default('tag');
            $table->timestamp('created_at')->useCurrent();
        });

        $schema->create('error_group_tags', function (Blueprint $table): void {
            $table->integer('group_id');
            $table->integer('tag_id');
            $table->string('source', 30)->default('manual');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['group_id', 'tag_id']);
            $table->foreign('group_id')->references('id')->on('error_groups')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });

        $schema->create('tag_rules', function (Blueprint $table): void {
            $table->id();
            $table->integer('tag_id');
            $table->text('match_word');
            $table->integer('enabled')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });

        $schema->create('app_settings', function (Blueprint $table): void {
            $table->string('key', 191); // primary key set explicitly below (composite-key primary() is for multi-column; this one is single-column but not auto-increment)
            $table->text('value');
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['key']);
        });

        $schema->create('alert_channels', function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->text('type');
            $table->text('target_token');
            $table->integer('enabled')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        $schema->create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->integer('channel_id');
            $table->text('trigger_type');
            $table->text('severities'); // app always supplies this explicitly on INSERT — see MysqlGrammar
            $table->integer('module_id')->nullable();
            $table->integer('min_count')->default(1);
            $table->real('spike_factor')->default(3);
            $table->integer('cooldown_minutes')->default(60);
            $table->integer('enabled')->default(1);
            $table->text('last_triggered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('channel_id')->references('id')->on('alert_channels')->onDelete('cascade');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('set null');
        });

        $schema->create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->integer('rule_id')->nullable();
            $table->integer('channel_id')->nullable();
            $table->text('trigger_type');
            $table->text('status');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('rule_id')->references('id')->on('alert_rules')->onDelete('cascade');
            $table->foreign('channel_id')->references('id')->on('alert_channels')->onDelete('set null');
        });

        $schema->create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('version');
            $table->string('environment')->nullable();
            $table->text('ref')->nullable();
            $table->text('notes')->nullable();
            $table->text('deployed_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['version', 'environment'], 'ux_releases_version_environment');
        });

        $schema->create('source_maps', function (Blueprint $table): void {
            $table->id();
            // `release` is a reserved word in MySQL (RELEASE SAVEPOINT) —
            // Blueprint/Grammar quote every column name, so the bare name
            // here is safe on every engine (see AbstractGrammar::quotedColumns()).
            $table->string('release');
            // Bounded to 300 (not the usual 600 for path-like columns), so
            // the composite UNIQUE below stays under InnoDB's 3072-byte key
            // length limit under utf8mb4 (4 bytes/char): (191+300)*4 = 1964.
            $table->string('file', 300);
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['release', 'file'], 'ux_source_maps_release_file');
        });
    }

    public function down(Schema $schema): void
    {
        // Reverse dependency order.
        foreach ([
            'source_maps', 'releases', 'alert_events', 'alert_rules', 'alert_channels',
            'app_settings', 'tag_rules', 'error_group_tags', 'tags', 'issue_status_history',
            'occurrences', 'error_groups', 'source_files', 'connector_sync_runs',
            'source_streams', 'connectors', 'modules',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
