# Log Lens — core engine

[![packagist](https://img.shields.io/packagist/v/cliqthemes/log-lens-core?label=packagist&color=blue&logo=packagist&logoColor=white)](https://packagist.org/packages/cliqthemes/log-lens-core)
[![downloads](https://img.shields.io/packagist/dt/cliqthemes/log-lens-core?label=downloads&color=blue&logo=packagist&logoColor=white)](https://packagist.org/packages/cliqthemes/log-lens-core)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-blue)

**[Documentation](https://docs.log-lens.cliqthemes.com/) · [Website](https://log-lens.cliqthemes.com)**

The runnable, framework-free engine behind [Log Lens](https://log-lens.cliqthemes.com): a fast,
local-first log dashboard and error tracker for Laravel, Horizon, nginx access,
and console logs. PHP + PDO, SQLite by default, no framework.

Log data stays on the machine running the app. One application is selected at a
time, and each owns a completely isolated database and set of directories —
never an aggregated view across applications.

[![The Log Lens issue dashboard: sources, level and status distribution, indexed-event stats, and fingerprinted issues with status and tags](https://log-lens.cliqthemes.com/screenshots/dashboard.png)](https://log-lens.cliqthemes.com/screenshots/dashboard.png)

To mount the same dashboard inside an existing Laravel app instead, use the
adapter: [`cliqthemes/log-lens`](https://packagist.org/packages/cliqthemes/log-lens).

## Requirements

- PHP **8.2+** with `pdo`, `pdo_sqlite`, and `json`.
- Nothing else at runtime. No Composer dependencies, and the React UI ships
  pre-built under `public/ui/`.
- Optional: `curl` for the outbound-HTTP plugins (Linear, Alerting, HTTP
  ingest); `pdo_pgsql` or `pdo_mysql` for the opt-in Postgres/MySQL drivers.

## Run

Serve `public/`:

```bash
php -S 127.0.0.1:8787 -t public
```

The first launch creates a default application, its SQLite database under
`storage/`, and `logs/`, `processed/`, and `sources/` directories. Drop log
files into `logs/` and press **Process logs**, or import in place:

```bash
php bin/import.php --app=default /path/to/laravel.log
```

## What it does

- **Parses** Laravel, Horizon (both the logging-stack framing and raw
  `queue:work` console output), nginx access logs, and arbitrary console output.
  Hosts can register their own parsers.
- **Groups** recurring events into fingerprinted issues with a workflow status,
  an immutable status history, tags, modules, and assignment — while keeping the
  raw byte range so the original event text is always retrievable.
- **Pulls** logs incrementally from a local directory or over SSH, with
  byte-offset checkpoints, rotation handling, a background worker, and a
  scheduled drain.
- **Extends** through opt-in plugins: Linear, Alerting, HTTP ingest, release
  tracking with source maps, and access-log analytics.
- **Captures** first-hand errors over `POST ?api=ingest`, with PHP and browser
  SDKs.

## Install

```bash
git clone https://github.com/cliqthemes/log-lens-core.git log-lens
cd log-lens && php -S 127.0.0.1:8787 -t public
```

Or as a Composer dependency, if you are embedding the engine:

```bash
composer require cliqthemes/log-lens-core
```

## Documentation

Full documentation: **https://docs.log-lens.cliqthemes.com/**

[Getting Started](https://docs.log-lens.cliqthemes.com/Getting-Started) ·
[Architecture](https://docs.log-lens.cliqthemes.com/Architecture) ·
[Configuration](https://docs.log-lens.cliqthemes.com/Configuration) ·
[Deployment](https://docs.log-lens.cliqthemes.com/Deployment) ·
[API reference](https://docs.log-lens.cliqthemes.com/reference/api-conventions) ·
[Database drivers](https://docs.log-lens.cliqthemes.com/reference/database-drivers) ·
[Security](https://docs.log-lens.cliqthemes.com/Security)

## Tests

```bash
composer install      # PHPUnit only
composer test
composer lint
```

The cross-engine smoke suites under `tests/smoke/` need a live Postgres or MySQL
(or, for the two SQLite ones, nothing at all) — see their file headers.

MIT licensed. Release history:
[releases](https://github.com/cliqthemes/log-lens-core/releases). Bugs, requests,
and security advisories for both packages go to
[cliqthemes/log-lens](https://github.com/cliqthemes/log-lens/issues).
