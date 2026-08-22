---
name: log-lens-issues-claude
description: Investigate, triage, fix, and update one or many Log Lens issues through its JSON API. Use in Claude Code for requests such as fixing a specific issue, working through unresolved errors in one application, fixing the top N issues by occurrence count, resolving issues from a date or module such as Billing, filtering by severity/source/type/tag/status, or updating Log Lens workflow status after a validated code fix.
---

# Resolve Log Lens issues with Claude Code

Treat Log Lens as the evidence source and workflow ledger. Treat the current Claude Code working directory—the application folder opened in the editor or the repository where Claude Code was launched—as the target project to inspect, edit, and test. Do not edit the Log Lens repository merely because this skill is stored there. Carry a batch request through issue discovery, diagnosis, code changes, validation, and status updates without asking the user to select each issue.

## Establish the target application

At the start of the task:

1. Resolve the current Git root with `git rev-parse --show-toplevel` when available; otherwise use the current working directory.
2. Treat that root as `TARGET_PROJECT_ROOT`.
3. Read `CLAUDE.md` and project instructions applicable to that root.
4. Confirm the project resembles the application from the issue evidence using package/namespace names, repository layout, dependency manifests, and source frames.
5. Perform all application searches, edits, tests, and validation inside `TARGET_PROJECT_ROOT`.

Do not require the target project to contain this skill, Log Lens, `config.php`, or a Log Lens database. The normal workflow is:

```text
Editor / Claude Code workspace = application being fixed
Log Lens URL                  = separate evidence and status service
skill installation directory = reusable workflow only
```

If the current workspace clearly does not contain the logged application, search only obvious editor multi-root workspace folders or explicitly supplied paths. Ask the user for the correct application folder when more than one checkout matches or no checkout matches. Do not silently edit a similarly named repository.

## Resolve the Log Lens origin

Resolve the base URL in this order:

1. Resolve this `SKILL.md` to its real path, following a user-level symlink when present. The skill ships inside the engine, at `<LOG_LENS>/skills/claude/SKILL.md`, so walk up two directories to the engine root and read `<LOG_LENS>/config.php`. `<LOG_LENS>` is a standalone install, `vendor/cliqthemes/log-lens-core` in a Composer project, or `packages/core` in the development monorepo — the same two-directory walk in every case. Do not search for `config.php` inside `TARGET_PROJECT_ROOT`.
2. When that Log Lens config exists, require it and read its returned `LOG_LENS_URL` value. A safe read is:
   `php -r '$c=require $argv[1]; echo $c["LOG_LENS_URL"] ?? "";' "/absolute/path/to/log-lens/config.php"`.
3. Use the `LOG_LENS_URL` environment variable when the Log Lens config file is not accessible.
4. Infer the origin from user context or a running Log Lens process.
5. As a last resort, probe a local guess (`http://localhost`, or the app's dev URL) with `GET /?api=summary`.
6. If nothing responds with Log Lens JSON, ask the user for the base URL rather than guessing a host or port.

Accept only a scalar HTTP(S) URL from config. Remove a trailing slash before appending `/?api=...`.

## Authenticate when a key is configured

Log Lens may require an API key. Resolve it once, before the first request:

1. Read `LOG_LENS_TOKEN` from the environment.
2. Otherwise read `auth.token` from the Log Lens config:
   `php -r '$c=require $argv[1]; echo $c["auth"]["token"] ?? "";' "/absolute/path/to/log-lens/config.php"`.

When a non-empty key is resolved, send it on every request as an `X-Log-Lens-Token: <key>` header (or `Authorization: Bearer <key>`). A `401` response means the key is missing or wrong—resolve or ask the user for it, then retry. When no key is configured, send no auth header.

Keep all log content local. Never send stacks, context, raw events, credentials, tokens, personal data, or payloads to external services.

## Select exactly one Log Lens application

After resolving the origin, call `GET /?api=applications` before any application-scoped endpoint.

1. If the user names an application, match its `id` or name case-insensitively.
2. Otherwise match the target repository name/path to an application id or name.
3. If exactly one application exists, select it.
4. If multiple applications remain plausible, ask the user which one to use.

Store the selected id as `LOG_LENS_APP` for the task and append `app=<URL-encoded id>` to every subsequent Log Lens read or mutation. There is no all-applications mode. Never merge IDs, queues, tags, modules, or statuses across applications; numeric IDs are only meaningful inside the selected application's database.

## Translate the request into a queue

Use the lean `errors` endpoint for discovery, paginate until the requested candidate set is complete, then investigate issues one at a time.

| User intent | Query plan |
|---|---|
| Specific ID | Fetch `error&id={id}` directly. |
| Top 3 by occurrence | `errors&sort=occurrences&limit=3&page=1`. |
| All issues from 21 July 2026 | `errors&date=2026-07-21&sort=occurrences`, following every page. |
| All unresolved / not fixed | Query `open`, `in_progress`, and `reoccurred` separately, follow every page, merge by ID, then sort globally. Exclude terminal `wont_fix` unless requested. |
| Search text | Add `q={text}`. |
| All Billing module issues | Add `module=billing`; module accepts an id, slug, or exact name inside the selected application. |
| Exact error-level only | Add `severity=ERROR`. Treat the casual word “errors” as issue groups across retained severities unless the user says `ERROR severity` or `error-level`. |
| Top N unresolved | Fetch every page for the three active statuses, merge, sort by numeric `count` descending and `last_seen` descending, then take N. Do not take N from each status. |

All list filters combine with AND semantics. Preserve the selected `app` and any requested module, date, severity, source, log type, tag, or search filters across every status/page request.

For a batch:

1. Build a stable queue of issue IDs and summary fields.
2. Process sequentially. Re-fetch an issue immediately before working on it because status or occurrences may have changed.
3. Mark only the current issue `in_progress`; never pre-mark the whole queue.
4. Continue to the next independent issue after a fix, a confirmed duplicate, or an issue-specific blocker.
5. Keep a concise outcome ledger: fixed, already fixed, duplicate/covered, still in progress, or blocked.
6. A single code change may fix multiple groups. Validate and update every affected Log Lens ID separately with an issue-specific note.

## Investigate adaptively

Start discovery lean: omit stack and context from collection requests.

For each selected issue:

1. Fetch `error&id={id}&skip_vendor=true`. Use `include_context=false` only when the title, exception, and application frame are already sufficient.
2. Read the representative message, application frames, timeline, recent occurrences, status history, and tags.
3. Fetch context when diagnosis depends on SQL, input values, request/job metadata, identifiers, HTTP details, or structured exception data.
4. Start with vendor frames hidden. Refetch with `skip_vendor=false` when the framework boundary, middleware pipeline, exception construction, ORM behavior, or queue internals may explain the fault.
5. Fetch `source&occurrence={id}` when representative data is truncated, malformed, ambiguous, varies between occurrences, or when a date-scoped request needs the exact event from that day.
6. For a date-scoped request, select a raw occurrence whose `occurred_at` matches the requested date; issue `count` is lifetime frequency, not that day's frequency.
7. Compare multiple raw occurrences when recurrence may have more than one input shape.

Do not request heavy stack/context projections for hundreds of list rows.

Some issues are not parsed from logs. An `origin=linear` issue (mirrored from Linear) or an `origin=manual` issue has `count=0` and no raw `source&occurrence` events — treat its title, message, and any linked context or description as the evidence, and do not chase occurrences or raw source for it. A pushed HTTP event (`log_type=http`, `origin=ingested`) does carry occurrences and behaves like a parsed log error. Log Lens features are optional plugins; an endpoint that returns `404` for a disabled plugin is expected, not an error to work around.

## Map production evidence to local code

Assume Claude Code usually runs in a local development checkout with different paths, data, configuration, dependencies, services, and possibly a different revision from production.

Production paths are evidence, not literal local paths. Strip deployment prefixes and preserve the project-relative suffix:

```text
/var/www/product/releases/20260721_170000/app/Services/FeedImporter.php:84
                                                ↓
app/Services/FeedImporter.php:84

/srv/www/current/routes/console.php:19
                    ↓
routes/console.php:19
```

Map the project-relative suffix into the local repository root regardless of language or framework—common source roots include `src/`, `app/`, `lib/`, `pkg/`, `internal/`, `routes/`, and `tests/`. Use module/namespace names, class or function names, and nearby application frames when line numbers differ. Do not edit dependency directories (`vendor/`, `node_modules/`, virtualenv `site-packages/`, and equivalents); inspect them only to understand third-party behavior.

Before editing inside `TARGET_PROJECT_ROOT`:

- read its `CLAUDE.md` and repository instructions;
- inspect the current implementation and relevant tests;
- account for production/local version drift;
- preserve unrelated changes.

If multiple checkouts plausibly match, ask which repository to edit. If production-only state cannot be reproduced locally, create a focused test fixture from safely redacted evidence and state the remaining environment uncertainty.

## Status lifecycle

Once active investigation begins, POST `in_progress` with a concise note naming the evidence being investigated. If already `in_progress`, a same-status note may record resumed work.

Diagnose before editing. Implement the smallest complete fix with regression coverage. Run a narrow reproduction/test first, then broader relevant checks.

After validation:

- mark `fixed` automatically;
- include cause, changed behavior/files, and exact validation in the note;
- re-fetch the issue and verify status plus history.

Do not mark `fixed` from a code diff alone. Leave `in_progress` with a blocker note when validation is unavailable or the diagnosis is unresolved. Use `wont_fix` only when the user or project policy explicitly accepts the behavior. A future matching import automatically changes `fixed` to `reoccurred`.

When an issue has `origin=linear` and Linear status write-back is enabled, changing its status also comments on and may transition the linked Linear issue tracked by the wider team. Do not change the status of a Linear-sourced issue during an autonomous batch unless the user intends that outward effect; the `issue-status` response carries a `linear` object describing what was written back.

Use this note shape:

```text
Cause: <root cause tied to log evidence>.
Fix: <behavior and important files changed>.
Validated: <tests/reproduction and result>.
```

## Embedded Log Lens API reference

All paths below are relative to the resolved base URL. Responses are JSON. Query values must be URL encoded. Mutation bodies use `Content-Type: application/json`.

### List applications and select scope

```http
GET /?api=applications
```

This is the only unscoped endpoint. It returns isolated application records with `id`, `name`, `database`, `logs`, and `processed`. Append the selected `app` id to every endpoint below.

### List normalized issues

```http
GET /?api=errors&app=my-app
```

Supported query parameters:

| Name | Values and behavior |
|---|---|
| `app` | Required by this skill: exactly one application id selected from `api=applications`. |
| `q` | Case-insensitive search across title, representative message, exception, application frame, channel, and source path. |
| `date` | `YYYY-MM-DD`; retain ingested groups with an occurrence that day and manual issues created that day. |
| `severity` | Exact `EMERGENCY`, `ALERT`, `CRITICAL`, `ERROR`, `WARNING`, `NOTICE`, `INFO`, or `DEBUG`. |
| `status` | Exact `open`, `in_progress`, `fixed`, `wont_fix`, or `reoccurred`; only one status per request. |
| `log_type` | Exact `laravel`, `horizon`, `nginx_access`, `console`, `manual`, `linear`, or `http` (HTTP-pushed events). |
| `origin` | Exact `ingested` (parsed logs and HTTP-pushed events), `manual` (user-created), or `linear` (mirrored from Linear). |
| `kind` | Exact `error`, `issue`, `bug`, `feature_request`, or `task`. |
| `module` | Application-local module id, slug, or case-insensitive exact name. |
| `source` | Exact channel or partial archived source path. |
| `tag` | Numeric tag ID or case-insensitive tag name. |
| `has_stack` | `true` for parsed-stack issues, `false` for issues without a stack. |
| `include_stack` | Default `false`; add representative stack to list items. |
| `skip_vendor` | Default `false`; remove lines containing `/vendor/`; list requests also require `include_stack=true`. |
| `include_context` | Default `false`; add representative context and message. |
| `sort` | `newest`, `oldest`, or `occurrences`; API default is `newest`. |
| `page` | One-indexed, default `1`. |
| `limit` | Default `50`, constrained to `1–200`. |

Response:

```json
{
  "data": [{
    "id": 15,
    "severity": "ERROR",
    "title": "Database query failed",
    "exception_class": "Illuminate\\Database\\QueryException",
    "source_frame": "/releases/20260721/app/Services/Import.php:42",
    "count": 42,
    "first_seen": "2026-07-20 09:14:00",
    "last_seen": "2026-07-24 16:42:12",
    "status": "open",
    "log_type": "laravel",
    "channel": "laravel.log",
    "origin": "ingested",
    "kind": "error",
    "module_id": 3,
    "module_name": "Billing",
    "module_slug": "billing",
    "has_stack": 1,
    "tags": []
  }],
  "meta": {"total": 144, "page": 1, "limit": 50, "pages": 3}
}
```

Follow pages through `meta.pages`. Do not assume one page is complete.

### Create a manual issue

```http
POST /?api=issues&app=my-app
Content-Type: application/json

{"title":"Add audit export","description":"Acceptance criteria and context…","kind":"feature_request","severity":"INFO","status":"open","module_id":3,"source_frame":"app/Services/AuditExport.php","environment":"local","tag_ids":[2],"note":"Requested by operations."}
```

`title` is required. `kind` is `issue`, `bug`, `feature_request`, or `task`; all other fields are optional. The response is `201` with standard issue detail under `data`. Manual records have `origin=manual`, `log_type=manual`, `count=0`, and no raw occurrences. Use this endpoint only when the user asks to create tracked work; do not turn an already-ingested error into a duplicate manual issue.

List module ids with `GET /?api=modules&app=my-app`. Modules and tags are local to that application.

### Read one issue and its occurrences

```http
GET /?api=error&app=my-app&id=15&skip_vendor=true&include_context=true&page=1&limit=100
```

- `id` is required.
- `skip_vendor` defaults to `false`.
- `include_context` defaults to `true`.
- occurrence `page` defaults to `1`; `limit` defaults to `100` and is constrained to `1–500`.
- unknown IDs return `404`.

The response contains `data` (full group/message/stack/context), `occurrences` (IDs, timestamps, byte ranges, source paths, channels, and context previews), `timeline` (`day`,`count`), `status_history`, `tags`, and occurrence pagination in `meta`.

### Read an exact raw occurrence

```http
GET /?api=source&app=my-app&occurrence=912
```

Response fields are `path`, `log_type`, `channel`, `byte_start`, `byte_end`, and `content`. The archived source must still exist. Content is capped at 4 MiB.

### Update workflow status or add a note

```http
POST /?api=issue-status&app=my-app
Content-Type: application/json

{"id":15,"status":"fixed","note":"Cause: … Fix: … Validated: …"}
```

`id` and a valid status are required; `note` is optional. Every request creates immutable history, including same-status note updates. Response fields are `id`, `from_status`, `status`, and `note`.

Serialize JSON safely; do not concatenate dynamic log text into shell JSON.

### Bulk status or tag mutation

```http
POST /?api=bulk-issues&app=my-app
Content-Type: application/json

{"ids":[15,18],"action":"status","status":"in_progress","note":"Bulk triage note."}
```

Actions are `status`, `add_tag`, and `remove_tag`; tag actions require `tag_id`. Status mutations create one immutable history entry per issue. During automated code fixing, prefer the sequential lifecycle above so each issue receives diagnosis- and validation-specific notes; use bulk mutation only when the user explicitly requests a shared triage action.

### Supporting read endpoints

```http
GET /?api=summary&app=my-app
GET /?api=sources&app=my-app&page=1&limit=12
GET /?api=tags&app=my-app
GET /?api=connectors&app=my-app
GET /?api=connector-runs&app=my-app&limit=25
```

Use `summary` to verify the service and inspect totals/types. `sources` is paginated with a maximum limit of 100. `tags` returns definitions, rules, and assignment counts. Connectors and their run history are application-local operational evidence; they are not required for ordinary issue fixing.

### Synchronize logs only when requested

Do not create, edit, delete, test, or run connectors during issue fixing unless the user explicitly asks to fetch or synchronize logs. When they do:

```http
POST /?api=connector-test&app=my-app
Content-Type: application/json

{"id":2}

POST /?api=connector-preview&app=my-app
Content-Type: application/json

{"id":2}

POST /?api=connector-sync&app=my-app
Content-Type: application/json

{"id":2,"snapshot":"snapshot-returned-by-preview"}
```

List connectors, select the requested connector unambiguously, test it, preview its exact files, synchronize it with the returned snapshot, inspect `connector-runs`, and then rebuild the issue queue. Preview all enabled connectors with an empty JSON body only when the user explicitly requests all sources, then pass the returned connector snapshots to synchronization. Synchronization is incremental and reconciles matching manual imports, but it may legitimately create newly discovered issues from appended, daily, or rotated log files.

### Tag mutations when explicitly needed

```http
POST /?api=issue-tags&app=my-app
{"group_id":15,"tag_id":1,"action":"add"}
```

Use `action:"remove"` to unassign. Tag-definition CRUD is available at `/?api=tags` with POST, PATCH/PUT, and DELETE, but do not alter tag definitions during issue fixing unless requested.

### Error handling

- `404`: issue, occurrence source, or tag unavailable; refresh the queue.
- `405`: wrong HTTP method.
- `422`: invalid filter or request field; correct the request rather than skipping silently.
- `500`: unexpected Log Lens failure; report it without exposing sensitive payloads.

If a status mutation times out, re-fetch the issue before retrying so an accepted request does not create an unnecessary duplicate history entry.
