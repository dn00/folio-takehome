# Folio Architecture

## Overview

Folio is a PHP document-sharing internal tool. Staff create documents and generate one-time share links for recipients. Built on PHP 8.3 + SQLite, served via PHP's built-in dev server, containerized with Docker.

## Stack

| Layer | Technology |
|-------|-----------|
| Runtime | PHP 8.3 CLI |
| Database | SQLite 3 (single file: `db.sqlite`) |
| Server | PHP built-in server (`php -S`) |
| Container | Docker + Docker Compose |
| Frontend | Server-rendered HTML, vanilla CSS |

## Directory Structure

```
.
├── public/                  # Web root (document root for PHP server)
│   ├── index.php            # Redirect → admin.php
│   ├── admin.php            # Staff dashboard: create docs, list, link to share
│   ├── share.php            # Generate share link for a document
│   ├── view.php             # Recipient views document via token
│   └── assets/
│       └── style.css        # Global styles
├── lib/
│   ├── bootstrap.php        # DB connection, helpers (audit_log, current_staff,
│   │                        #   h, random_token, parse_publish_at,
│   │                        #   generate_readable_id)
│   └── layout.php           # HTML header/footer rendering
├── migrations/              # Numbered SQL migrations (001_*.sql, 002_*.sql, ...)
│   ├── 001_add_publish_at.sql
│   └── 002_add_readable_id.sql
├── migrate.php              # Migration runner (idempotent, transactional per file)
├── tests/
│   ├── test.php             # Entry harness — auto-discovers test_*.php suites
│   ├── test_migrate.php     # Migration runner tests
│   ├── test_timezone.php    # PHP/SQLite timezone alignment regression tests
│   ├── test_scheduled_publishing.php  # F1 tests
│   └── test_readable_id.php # F2 tests
├── schema.sql               # Base schema (applied on fresh seed; migrations layered after)
├── seed.php                 # Drops + recreates db.sqlite, runs migrations, seeds sample data
├── Dockerfile               # PHP 8.3 + pdo_sqlite
├── docker-compose.yml       # Single service, mounts repo, exposes :8000
└── docs/
    ├── ARCHITECTURE.md      # This file
    ├── CONVENTIONS.md       # Project rules
    └── ISSUES.md            # Initial gap analysis
```

## Entry Points

| URL | File | Auth | Purpose |
|-----|------|------|---------|
| `/` | `public/index.php` | — | 302 redirect to `/admin.php` |
| `/admin.php[?q=term]` | `public/admin.php` | Staff (hardcoded user #1) | Create documents (with optional publish_at), view list. Optional `q` filters by title (LIKE contains, case-insensitive). |
| `/share.php?doc={readable_id}` | `public/share.php` | Staff | Generate share link for a document |
| `/view.php?token={hex}[&doc={readable_id}]` | `public/view.php` | Public (token-gated) | Recipient views shared document. Optional `doc` param validated against token's document |

## Database Schema

Base schema (`schema.sql`) plus migrations in `migrations/`. Effective shape after all migrations:

### `staff`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| email | TEXT UNIQUE | |
| name | TEXT | |

### `documents`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| title | TEXT | |
| body | TEXT | |
| created_by | INTEGER FK → staff.id | |
| created_at | TEXT | Default `datetime('now')` (UTC) |
| publish_at | TEXT NULL | Added by migration 001. NULL = visible immediately. |
| readable_id | TEXT UNIQUE | Added by migration 002 (separate UNIQUE INDEX). Slug + 4 random alphanum. |

### `shares`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| document_id | INTEGER FK → documents.id | |
| token | TEXT UNIQUE | 32-char hex (16 random bytes) |
| recipient_email | TEXT | |
| created_at | TEXT | Default `datetime('now')` (UTC) |

### `audit_log`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| staff_id | INTEGER | Nullable |
| action | TEXT | e.g. "create" |
| entity_type | TEXT | e.g. "document", "share" |
| entity_id | INTEGER | |
| details | TEXT | JSON blob |
| created_at | TEXT | Default `datetime('now')` (UTC) |

### `migrations` (managed by `migrate.php`)
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| filename | TEXT UNIQUE | e.g. `001_add_publish_at.sql` |
| applied_at | TEXT | Default `datetime('now')` |

## Key APIs / Internal Functions

`lib/bootstrap.php`:

| Function | Purpose |
|----------|---------|
| `db(): PDO` | Singleton SQLite connection with FK enforcement |
| `current_staff(): array` | Returns staff row #1 (no real auth) |
| `audit_log(action, entity_type, entity_id, details)` | Inserts audit trail entry |
| `random_token(bytes=16): string` | Hex token for share links |
| `h(string): string` | HTML-escape helper |
| `parse_publish_at(raw): null\|false\|string` | Parse `datetime-local` form value to UTC `Y-m-d H:i:s`. NULL=no schedule, FALSE=invalid input, string=valid UTC timestamp. Roundtrip-validated to catch DateTime overflow. |
| `generate_readable_id(title): string` | Build `{slug}-{4 alphanum}`. Slug: lowercase, non-alphanum→hyphen, trim, max 40 chars; fallback `doc` if empty. |

Layout helpers in `lib/layout.php`:

| Function | Purpose |
|----------|---------|
| `render_header(title, staff?)` | Outputs `<html>` through `<main>` open |
| `render_footer()` | Closes `</main></body></html>` |

## Data Flow

```
Staff creates document:
  POST /admin.php
    → parse_publish_at($_POST['publish_at']) — validate or reject
    → generate_readable_id($title) — with retry on UNIQUE collision (max 3 attempts)
    → INSERT documents
    → audit_log("create","document",{title, publish_at, readable_id})
    → redirect /admin.php?created={readable_id}

Staff generates share link:
  GET  /share.php?doc={readable_id} — lookup by readable_id
  POST /share.php?doc={readable_id} — INSERT shares (random token)
                                    → audit_log("create","share")
                                    → render share URL: /view.php?doc={readable_id}&token={hex}

Recipient views document:
  GET /view.php?token={hex}[&doc={readable_id}]
    → SELECT shares JOIN documents WHERE token = ?
    → if no row: 404
    → if doc param present and != document.readable_id: 404 (mismatched URL)
    → if publish_at > now (UTC): 403 "Not yet available"
    → else: render document

Staff searches documents:
  GET /admin.php?q={term}
    → escape LIKE wildcards (% and _) in $term
    → SELECT ... WHERE title LIKE ? ESCAPE '\' (double-quoted PHP string)
    → render filtered list with result count or empty state
    → no audit log (search is a read, not a mutation)
```

## Authentication Model

None. `current_staff()` always returns staff row #1 ("Freddy Folio"). No login, no sessions. Recipient access is gated solely by knowledge of the share token. Readable IDs are non-secret; tokens are authoritative.

## Runtime Lifecycle

1. `docker compose up` builds image (PHP 8.3 + pdo_sqlite)
2. Container runs `php seed.php` — drops `db.sqlite`, applies `schema.sql`, runs `migrate.php` (applies any unapplied migrations in `migrations/`), inserts sample data
3. Container starts `php -S 0.0.0.0:8000 -t public/`
4. Host volume mount means file edits reflect immediately on refresh

## Testing

`tests/test.php` is the entry point. It runs the inline seeded-share test, then auto-discovers all `tests/test_*.php` files and runs each in a subprocess, propagating non-zero exit codes. Execute via:

```
docker compose exec app php tests/test.php
```

Each suite re-seeds the DB at start and cleans up rows it inserts.

## Migration System

`migrate.php` is a procedural script, also included from `seed.php`:
- Creates `migrations` table if absent
- Scans `migrations/*.sql` (sorted by filename), skips already-applied
- For each unapplied: opens transaction, splits on `;`, executes per statement, records in `migrations` table, commits. On error: rolls back and re-throws. Empty/whitespace-only files are skipped without recording.

## Design Notes

- All schema changes go through migration files. `schema.sql` is the base; migrations are layered after.
- Timestamps are stored as UTC (PHP timezone is set to UTC in `bootstrap.php`; SQLite `datetime('now')` is UTC).
- Share tokens are 128-bit random hex — unguessable, not human-readable.
- Readable IDs are slug + 4 random alphanum (~1.6M space per slug prefix). Non-secret; never used for access control.
- Token authoritative for view access; `doc` param in the share URL is informational and validated.
- `publish_at` NULL means "immediately visible" (backwards compat for existing documents).
- All queries use prepared statements (no SQL injection).
- Output escaping via `h()` (no XSS). `h()` is currently not null-safe — fixed in cleanup pass for nullable fields.
- No CSRF protection on forms (production hardening, not in scope for spec).
