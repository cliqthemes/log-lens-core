<?php
declare(strict_types=1);

/**
 * Builds an authentic pre-Migrator SQLite database (schema exactly as the
 * old inline runMigration()/addColumn() produced it, PRAGMA user_version=8)
 * so we can verify the new Migrator's legacy-install bridge against a real
 * legacy shape, not a guess. Body copied verbatim from commit 98eab07's
 * SqliteDriver.php (the last commit before the migration-system rewrite).
 */
final class LegacySchemaBuilder
{
    public static function build(PDO $pdo): void
    {
        self::runMigration($pdo);
        $pdo->exec('PRAGMA user_version=8');
    }

    private static function runMigration(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS modules (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
    color TEXT NOT NULL DEFAULT '#6366f1',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS connectors (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    type TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL,
    config_json TEXT NOT NULL DEFAULT '{}',
    last_synced_at TEXT,
    last_status TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS source_streams (
    id INTEGER PRIMARY KEY,
    connector_id INTEGER NOT NULL REFERENCES connectors(id) ON DELETE CASCADE,
    module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL,
    remote_path TEXT NOT NULL,
    remote_identity TEXT NOT NULL,
    generation INTEGER NOT NULL DEFAULT 1,
    local_path TEXT NOT NULL UNIQUE,
    source_file_id INTEGER,
    remote_size INTEGER NOT NULL DEFAULT 0,
    fetched_offset INTEGER NOT NULL DEFAULT 0,
    remote_modified_at INTEGER,
    prefix_hash TEXT,
    last_synced_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(connector_id,remote_path,generation)
);
CREATE TABLE IF NOT EXISTS connector_sync_runs (
    id INTEGER PRIMARY KEY,
    connector_id INTEGER NOT NULL REFERENCES connectors(id) ON DELETE CASCADE,
    status TEXT NOT NULL,
    files_discovered INTEGER NOT NULL DEFAULT 0,
    files_completed INTEGER NOT NULL DEFAULT 0,
    files_updated INTEGER NOT NULL DEFAULT 0,
    bytes_total INTEGER NOT NULL DEFAULT 0,
    bytes_fetched INTEGER NOT NULL DEFAULT 0,
    events_indexed INTEGER NOT NULL DEFAULT 0,
    current_file TEXT,
    expected_snapshot TEXT,
    message TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT
);
CREATE TABLE IF NOT EXISTS source_files (
    id INTEGER PRIMARY KEY,
    path TEXT NOT NULL UNIQUE,
    size INTEGER NOT NULL DEFAULT 0,
    modified_at INTEGER NOT NULL DEFAULT 0,
    last_offset INTEGER NOT NULL DEFAULT 0,
    imported_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    parser_version INTEGER NOT NULL DEFAULT 1,
    log_type TEXT NOT NULL DEFAULT 'laravel',
    channel TEXT,
    module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL,
    source_stream_id INTEGER REFERENCES source_streams(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS error_groups (
    id INTEGER PRIMARY KEY,
    fingerprint TEXT NOT NULL UNIQUE,
    severity TEXT NOT NULL,
    environment TEXT,
    title TEXT NOT NULL,
    exception_class TEXT,
    source_frame TEXT,
    count INTEGER NOT NULL DEFAULT 0,
    first_seen TEXT NOT NULL,
    last_seen TEXT NOT NULL,
    sample_message TEXT,
    sample_stack TEXT,
    sample_context TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL DEFAULT 'open',
    status_updated_at TEXT,
    log_type TEXT NOT NULL DEFAULT 'laravel',
    channel TEXT,
    origin TEXT NOT NULL DEFAULT 'ingested',
    kind TEXT NOT NULL DEFAULT 'error',
    module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS occurrences (
    id INTEGER PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES error_groups(id) ON DELETE CASCADE,
    source_file_id INTEGER NOT NULL REFERENCES source_files(id) ON DELETE CASCADE,
    exact_fingerprint TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    severity TEXT NOT NULL,
    environment TEXT,
    byte_start INTEGER NOT NULL,
    byte_end INTEGER NOT NULL,
    context_preview TEXT,
    event_hash TEXT,
    UNIQUE(source_file_id, byte_start)
);
CREATE TABLE IF NOT EXISTS issue_status_history (
    id INTEGER PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES error_groups(id) ON DELETE CASCADE,
    from_status TEXT,
    to_status TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE,
    color TEXT NOT NULL DEFAULT '#64748b',
    icon TEXT NOT NULL DEFAULT 'tag',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS error_group_tags (
    group_id INTEGER NOT NULL REFERENCES error_groups(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(group_id, tag_id)
);
CREATE TABLE IF NOT EXISTS tag_rules (
    id INTEGER PRIMARY KEY,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    match_word TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alert_channels (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    target_token TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alert_rules (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    channel_id INTEGER NOT NULL REFERENCES alert_channels(id) ON DELETE CASCADE,
    trigger_type TEXT NOT NULL,
    severities TEXT NOT NULL DEFAULT '[]',
    module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL,
    min_count INTEGER NOT NULL DEFAULT 1,
    spike_factor REAL NOT NULL DEFAULT 3,
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    enabled INTEGER NOT NULL DEFAULT 1,
    last_triggered_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alert_events (
    id INTEGER PRIMARY KEY,
    rule_id INTEGER REFERENCES alert_rules(id) ON DELETE CASCADE,
    channel_id INTEGER REFERENCES alert_channels(id) ON DELETE SET NULL,
    trigger_type TEXT NOT NULL,
    status TEXT NOT NULL,
    detail TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS releases (
    id INTEGER PRIMARY KEY,
    version TEXT NOT NULL,
    environment TEXT,
    ref TEXT,
    notes TEXT,
    deployed_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(version, environment)
);
CREATE TABLE IF NOT EXISTS source_maps (
    id INTEGER PRIMARY KEY,
    release TEXT NOT NULL,
    file TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(release, file)
);
SQL);

        self::addColumn($pdo, 'source_files', "parser_version INTEGER NOT NULL DEFAULT 1");
        self::addColumn($pdo, 'source_files', "log_type TEXT NOT NULL DEFAULT 'laravel'");
        self::addColumn($pdo, 'source_files', 'channel TEXT');
        self::addColumn($pdo, 'source_files', 'module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL');
        self::addColumn($pdo, 'source_files', 'source_stream_id INTEGER REFERENCES source_streams(id) ON DELETE SET NULL');
        self::addColumn($pdo, 'error_groups', "status TEXT NOT NULL DEFAULT 'open'");
        self::addColumn($pdo, 'error_groups', 'status_updated_at TEXT');
        self::addColumn($pdo, 'error_groups', "log_type TEXT NOT NULL DEFAULT 'laravel'");
        self::addColumn($pdo, 'error_groups', 'channel TEXT');
        self::addColumn($pdo, 'error_groups', "origin TEXT NOT NULL DEFAULT 'ingested'");
        self::addColumn($pdo, 'error_groups', "kind TEXT NOT NULL DEFAULT 'error'");
        self::addColumn($pdo, 'error_groups', 'module_id INTEGER REFERENCES modules(id) ON DELETE SET NULL');
        // Schema v3: externally-sourced issues (e.g. Linear). origin='linear'
        // rows carry the upstream identifiers so they can be de-duplicated on
        // re-sync and linked back to their source system from the UI.
        self::addColumn($pdo, 'error_groups', 'external_source TEXT');
        self::addColumn($pdo, 'error_groups', 'external_id TEXT');
        self::addColumn($pdo, 'error_groups', 'external_url TEXT');
        self::addColumn($pdo, 'error_groups', 'assignee TEXT');
        self::addColumn($pdo, 'occurrences', 'event_hash TEXT');
        self::addColumn($pdo, 'occurrences', 'release TEXT');
        self::addColumn($pdo, 'connector_sync_runs', 'files_completed INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, 'connector_sync_runs', 'bytes_total INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, 'connector_sync_runs', 'current_file TEXT');
        self::addColumn($pdo, 'connector_sync_runs', 'expected_snapshot TEXT');
        self::addColumn($pdo, 'connector_sync_runs', 'updated_at TEXT');
        $pdo->exec(
            'UPDATE connector_sync_runs SET updated_at=COALESCE(updated_at,finished_at,started_at,CURRENT_TIMESTAMP)'
        );
        self::addColumn(
            $pdo,
            'occurrences',
            'occurred_day TEXT GENERATED ALWAYS AS (substr(occurred_at,1,10)) VIRTUAL'
        );

        // Schema v7: surface the nginx access-log fields parsed into
        // context_preview as generated columns so access analytics can be
        // indexed instead of json_extract-scanning every occurrence (M-9). The
        // partial indexes below cover only access rows (access_status IS NOT
        // NULL), keeping them small and making the top-path / method / error
        // groupings index-driven.
        // json_valid guards are essential: context_preview is free text for
        // most occurrences (stack traces, context lines) and only JSON for
        // nginx access rows. Because these columns are indexed, SQLite evaluates
        // them at write time to maintain the index, so an unguarded json_extract
        // on non-JSON text would throw "malformed JSON" and fail the insert.
        self::addColumn(
            $pdo,
            'occurrences',
            "access_status INTEGER GENERATED ALWAYS AS (CASE WHEN json_valid(context_preview) THEN CAST(json_extract(context_preview,'\$.status') AS INTEGER) END) VIRTUAL"
        );
        self::addColumn(
            $pdo,
            'occurrences',
            "access_path TEXT GENERATED ALWAYS AS (CASE WHEN json_valid(context_preview) THEN json_extract(context_preview,'\$.path') END) VIRTUAL"
        );
        self::addColumn(
            $pdo,
            'occurrences',
            "access_method TEXT GENERATED ALWAYS AS (CASE WHEN json_valid(context_preview) THEN json_extract(context_preview,'\$.method') END) VIRTUAL"
        );
        self::addColumn(
            $pdo,
            'occurrences',
            "access_agent TEXT GENERATED ALWAYS AS (CASE WHEN json_valid(context_preview) THEN json_extract(context_preview,'\$.user_agent') END) VIRTUAL"
        );

        // Schema v8: attribute workflow status changes to the acting user (C-2).
        // NULL means a system/unattributed change (older rows, imports, Linear).
        self::addColumn($pdo, 'issue_status_history', 'actor TEXT');

        $pdo->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_groups_last_seen ON error_groups(last_seen DESC);
CREATE INDEX IF NOT EXISTS idx_groups_severity ON error_groups(severity);
CREATE INDEX IF NOT EXISTS idx_groups_status ON error_groups(status);
CREATE INDEX IF NOT EXISTS idx_groups_type ON error_groups(log_type);
CREATE INDEX IF NOT EXISTS idx_groups_origin ON error_groups(origin);
CREATE INDEX IF NOT EXISTS idx_groups_kind ON error_groups(kind);
CREATE INDEX IF NOT EXISTS idx_groups_module ON error_groups(module_id);
CREATE INDEX IF NOT EXISTS idx_groups_external ON error_groups(external_source,external_id);
CREATE INDEX IF NOT EXISTS idx_source_files_type ON source_files(log_type);
CREATE INDEX IF NOT EXISTS idx_source_files_module ON source_files(module_id);
CREATE INDEX IF NOT EXISTS idx_source_files_stream ON source_files(source_stream_id);
CREATE INDEX IF NOT EXISTS idx_occurrences_event_hash ON occurrences(event_hash);
CREATE INDEX IF NOT EXISTS idx_streams_connector_path ON source_streams(connector_id,remote_path);
CREATE INDEX IF NOT EXISTS idx_sync_runs_connector_time ON connector_sync_runs(connector_id,started_at DESC);
CREATE INDEX IF NOT EXISTS idx_occurrences_group_time ON occurrences(group_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_occurrences_time ON occurrences(occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_occurrences_day ON occurrences(occurred_day);
CREATE INDEX IF NOT EXISTS idx_status_history_group_time ON issue_status_history(group_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_group_tags_tag ON error_group_tags(tag_id);
CREATE INDEX IF NOT EXISTS idx_alert_rules_enabled ON alert_rules(enabled);
CREATE INDEX IF NOT EXISTS idx_alert_events_rule ON alert_events(rule_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_occurrences_release ON occurrences(release);
CREATE INDEX IF NOT EXISTS idx_releases_deployed ON releases(deployed_at DESC);
CREATE INDEX IF NOT EXISTS idx_source_maps_lookup ON source_maps(release,file);
CREATE INDEX IF NOT EXISTS idx_occ_access_time ON occurrences(occurred_at) WHERE access_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_occ_access_path ON occurrences(access_path, access_status) WHERE access_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_occ_access_method ON occurrences(access_method) WHERE access_status IS NOT NULL;
SQL);
    }

    private static function addColumn(PDO $pdo, string $table, string $definition): void
    {
        $name = strtok($definition, ' ');
        // table_xinfo (not table_info) also lists generated columns, so a future
        // migration re-run does not try to re-add an existing generated column.
        $columns = $pdo->query("PRAGMA table_xinfo({$table})")->fetchAll();
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
}
