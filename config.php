<?php
declare(strict_types=1);

/**
 * Log Lens configuration.
 *
 * Edit the values below to adapt Log Lens to your site, or set the two
 * deployment-specific secrets in the environment or a root-level `.env` file:
 *
 *   LOG_LENS_URL    Base URL the bundled Claude/Codex skills call.
 *   LOG_LENS_TOKEN  API key. When empty, the API is unauthenticated
 *                   (local-first default). When set, every API request must
 *                   send it as `X-Log-Lens-Token: <token>` or
 *                   `Authorization: Bearer <token>`.
 *
 * A real environment variable always wins over the same key in `.env`, so
 * `.env` is a convenience for setups (such as Herd) where exporting variables
 * into PHP-FPM is awkward.
 */

// Minimal, dependency-free `.env` reader. Real environment variables take
// precedence; `.env` only fills in keys that are not already set.
$dotenv = (static function (string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }
        $equals = strpos($line, '=');
        if ($equals === false) {
            continue;
        }
        $key = trim(substr($line, 0, $equals));
        $value = trim(substr($line, $equals + 1));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
})(__DIR__ . '/.env');

$readSetting = static function (string $key) use ($dotenv): string {
    $real = getenv($key);
    if (is_string($real) && trim($real) !== '') {
        return trim($real);
    }
    return isset($dotenv[$key]) ? trim($dotenv[$key]) : '';
};

$environmentUrl = $readSetting('LOG_LENS_URL');
$environmentToken = $readSetting('LOG_LENS_TOKEN');

// Optional Linear integration secrets. Provide the API key here to keep it out
// of the database entirely (it then cannot be changed from the UI); provide
// LOG_LENS_SECRET to encrypt any UI-entered secrets at rest.
$environmentLinearKey = $readSetting('LOG_LENS_LINEAR_API_KEY');
$environmentLinearWebhook = $readSetting('LOG_LENS_LINEAR_WEBHOOK_SECRET');
$environmentSecret = $readSetting('LOG_LENS_SECRET');

return [
    // Absolute base URL of this Log Lens install. Leave empty and it is derived
    // from the incoming request, so the dashboard works unchanged on any host,
    // port, or server (Herd, WAMP/MAMP, `php -S`, a container, a reverse proxy).
    // Set it explicitly only to pin a canonical URL — e.g. behind a proxy, or so
    // the bundled Claude/Codex skills know where to reach the API from the CLI.
    'LOG_LENS_URL' => $environmentUrl !== '' ? rtrim($environmentUrl, '/') : '',

    'auth' => [
        // Empty string disables authentication. Prefer LOG_LENS_TOKEN in the
        // environment or `.env` so the secret is not committed. Any non-empty
        // value turns auth on.
        'token' => $environmentToken,
    ],

    'ingestion' => [
        // Severities ingested by brand-new application workspaces. Each workspace
        // can later change this from the UI; existing workspaces are unaffected.
        'default_severities' => ['ERROR', 'WARNING'],

        // PCRE (with delimiters) matched against a file name to decide whether it
        // is a log file. Widen this for sites that name logs differently, e.g.
        // '/\.(log|out|err)(?:\.\d+)?$/i'.
        'log_file_pattern' => '/\.log(?:\.\d+)?$/i',

        // Bytes read from the head of a file to detect which parser handles it.
        'sample_bytes' => 65536,

        // Maximum bytes accumulated for a single multiline event body.
        'capture_limit' => 4194304,

        // Maximum characters stored per group sample (message, stack, context).
        'message_limit' => 120000,

        // Maximum characters stored for each occurrence's context preview.
        'context_preview_limit' => 12000,
    ],

    'parsing' => [
        // Register additional log-format parsers declaratively: a list of class
        // names implementing LogLens\Contracts\LogParserInterface. Each is
        // tried (in order, before the built-in formats) against a file's first
        // sample_bytes, first match wins — see LogLens\Parsing\ParserRegistry.
        // Bootstrap code may instead pass an explicit parser list straight to
        // ParserRegistry's constructor. Example:
        //   'register' => [\Acme\LogLens\SyslogParser::class],
        'register' => [],
    ],

    'pagination' => [
        // Default and hard-max page size for the issue list endpoints.
        'default_limit' => 50,
        'max_limit' => 200,
    ],

    'ingest' => [
        // HTTP ingest plugin rate limit, per application: the maximum number of
        // pushed events accepted per rolling window. Excess events in a batch
        // are dropped; a fully-exhausted window returns 429. This protects the
        // database from a looping client or a leaked (public) browser key.
        //
        // The limit uses a sliding-window counter (the previous window is
        // weighted by how far into the current window we are), so a burst
        // straddling a window boundary cannot briefly pass ~2x the limit the
        // way a fixed window allows.
        'rate_max_events' => 1000,
        'rate_window_seconds' => 60,
        // Optional secondary throttle keyed by source IP, over the same window.
        // 0 disables it. Useful when the ingest key is embedded in a public
        // browser SDK: it caps any single client without limiting overall
        // legitimate traffic. Requires a resolvable client IP (see
        // trust_forwarded_for); the per-IP table is pruned and size-capped.
        'rate_max_events_per_ip' => 0,
        // Trust the left-most X-Forwarded-For entry as the client IP. Enable
        // ONLY behind a trusted reverse proxy — otherwise clients can forge it
        // to evade the per-IP throttle. Default off: the unspoofable peer
        // (REMOTE_ADDR) is used. (Laravel uses its own trusted-proxy config.)
        'trust_forwarded_for' => false,
        // Hard cap on how many distinct IPs the per-IP throttle tracks at once,
        // bounding the size of its single settings row. Oldest entries are
        // evicted past this many.
        'rate_ip_table_max' => 1000,
    ],

    'sync' => [
        // Optional absolute path to the PHP CLI used by dashboard background sync.
        'php_binary' => '',
        // Optional POSIX shell path for Unix-like background launching.
        'shell_binary' => '',
        // Bytes fetched per round when appending from a connector source.
        'chunk_size' => 8388608,
        // Safety ceiling on files a single SSH pattern set may discover.
        'max_discovered_files' => 10000,
        // Prefix length hashed to reconcile connector streams with manual imports.
        'prefix_hash_bytes' => 1048576,
        // A running sync updates its progress every fetched chunk; if it stops
        // advancing for this many seconds it is treated as an interrupted worker
        // (crash/restart) and marked failed, so runs never linger as "running".
        'stale_run_timeout_seconds' => 900,
        // Cap for the detached worker's append-only log (bytes); it is rotated to
        // a single `.1` copy past this size. 0 disables rotation.
        'worker_log_max_bytes' => 5242880,
    ],

    'ssh' => [
        // Default port and connection timeout (seconds) for SSH connectors.
        'default_port' => 22,
        'connect_timeout' => 10,
    ],

    'database' => [
        // Storage engine (C-1). 'sqlite' (default) needs no configuration — one
        // file per application, as today. Postgres/MySQL are opt-in, never
        // enforced: set via LOG_LENS_DB_DRIVER or here directly. Both keep the
        // same per-application isolation SQLite gives for free, via
        // schema-per-application (Postgres) or database-per-application (MySQL)
        // instead of a separate file.
        'driver' => $readSetting('LOG_LENS_DB_DRIVER') !== '' ? $readSetting('LOG_LENS_DB_DRIVER') : 'sqlite',

        // Milliseconds SQLite waits on a locked database before giving up.
        'busy_timeout' => 5000,

        'pgsql' => [
            'host' => $readSetting('LOG_LENS_DB_HOST') !== '' ? $readSetting('LOG_LENS_DB_HOST') : '127.0.0.1',
            'port' => (int) ($readSetting('LOG_LENS_DB_PORT') !== '' ? $readSetting('LOG_LENS_DB_PORT') : 5432),
            // The single physical database every application's schema lives in.
            'database' => $readSetting('LOG_LENS_DB_NAME') !== '' ? $readSetting('LOG_LENS_DB_NAME') : 'log_lens',
            'username' => $readSetting('LOG_LENS_DB_USER') !== '' ? $readSetting('LOG_LENS_DB_USER') : 'postgres',
            'password' => $readSetting('LOG_LENS_DB_PASSWORD'),
            // Each application gets its own Postgres schema, named
            // "{schema_prefix}{application_id}", and connects with search_path
            // pinned to it — so every unqualified table reference in the
            // codebase keeps working unchanged, isolated per application.
            'schema_prefix' => 'app_',
        ],

        'mysql' => [
            'host' => $readSetting('LOG_LENS_DB_HOST') !== '' ? $readSetting('LOG_LENS_DB_HOST') : '127.0.0.1',
            'port' => (int) ($readSetting('LOG_LENS_DB_PORT') !== '' ? $readSetting('LOG_LENS_DB_PORT') : 3306),
            'username' => $readSetting('LOG_LENS_DB_USER') !== '' ? $readSetting('LOG_LENS_DB_USER') : 'root',
            'password' => $readSetting('LOG_LENS_DB_PASSWORD'),
            // Each application gets its own physical MySQL database, named
            // "{database_prefix}{application_id}" — MySQL has no lightweight
            // schema-within-database concept like Postgres, so this is the
            // closest per-application isolation SQLite's file-per-application
            // gives for free.
            'database_prefix' => 'log_lens_',
        ],
    ],

    'plugins' => [
        // Register third-party plugins declaratively: a list of class names that
        // implement LogLens\Plugins\PluginContract (or extend AbstractPlugin).
        // They join the built-in catalog in Settings → Plugins and can be toggled
        // per application. Bootstrap code may instead call
        // PluginRegistry::register(new MyPlugin()). Example:
        //   'register' => [\Acme\LogLens\JiraPlugin::class],
        'register' => [],
    ],

    'security' => [
        // Emit a strict Content-Security-Policy plus X-Content-Type-Options,
        // X-Frame-Options, and Referrer-Policy from the standalone front
        // controller. Turn off only if a reverse proxy sets its own. (The
        // Laravel embed always inherits the host application's headers.)
        'headers_enabled' => true,
        // Replace the built-in CSP wholesale. Empty keeps the default, which
        // is self-only for scripts/XHR and additionally allows Google Fonts
        // (fonts.googleapis.com / fonts.gstatic.com) for the appearance picker.
        'content_security_policy' => '',
        // Strict-Transport-Security, off by default and only ever sent over
        // TLS. Set the max-age in seconds (e.g. 31536000 for a year) once the
        // whole host is served over HTTPS. HSTS applies to the host, not to
        // Log Lens: every site sharing the host will also be forced onto HTTPS
        // for the duration, and a browser that has seen it cannot be told to
        // forget early — which is why this is opt-in.
        'hsts_max_age' => 0,
        // Extend the promise above to every subdomain. Only safe when every
        // subdomain of this host serves HTTPS.
        'hsts_include_subdomains' => false,
        // Advertise eligibility for the browser preload lists. Effectively
        // irreversible — read hstspreload.org before turning this on.
        'hsts_preload' => false,
    ],

    'linear' => [
        // Optional integration with Linear (linear.app) for pulling issues by
        // label / assignee into Log Lens. The integration is off until it is
        // enabled and given an API key from the dashboard Settings → Linear tab.
        //
        // api_key: when set here (LOG_LENS_LINEAR_API_KEY) it is the source of
        // truth, never stored in the database, and locked in the UI.
        'api_key' => $environmentLinearKey,
        // secret: key material for encrypting UI-entered secrets at rest. Without
        // it, a UI-entered API key is stored in a clearly-marked, unencrypted
        // envelope (still functional, but not protected against database theft).
        'secret' => $environmentSecret,
        // webhook_secret: shared secret for verifying inbound Linear webhooks.
        // May instead be set per-application from the UI.
        'webhook_secret' => $environmentLinearWebhook,
        // GraphQL endpoint and per-request timeout (seconds).
        'endpoint' => 'https://api.linear.app/graphql',
        'timeout' => 15,
        // Ceiling on how much one sync() call pulls (sync_max_pages *
        // sync_page_size issues) before it stops and persists a resumable
        // cursor for the next call — a workspace with more matches than that
        // needs several calls (manual "Sync now" clicks, or a webhook) to
        // fully backfill; every call afterwards is incremental.
        'sync_max_pages' => 5,
        'sync_page_size' => 50,
    ],

    'retention' => [
        // Automatically prune the processed/ archive after each incoming import.
        // Deleting an archived file only removes raw-event retrieval for its
        // occurrences; indexed issues and counts are untouched. 0 disables a rule.
        'processed_max_age_days' => 0, // delete archived logs older than N days
        'processed_max_files' => 0,    // keep only the N newest archived logs

        // Database-side retention: prune indexed occurrences to bound growth.
        // After pruning, ingested issues left with no occurrences are removed;
        // manual and Linear issues are never touched. 0 disables a rule.
        'occurrences_max_age_days' => 0,     // delete occurrences older than N days
        'max_occurrences_per_group' => 0,    // keep only the N newest per issue
        'occurrences_interval_seconds' => 900, // min seconds between automatic prunes
    ],
];
