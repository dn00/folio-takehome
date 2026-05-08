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
├── public/              # Web root (document root for PHP server)
│   ├── index.php        # Redirect → admin.php
│   ├── admin.php        # Staff dashboard: create docs, list docs, link to share
│   ├── share.php        # Generate share link for a document
│   ├── view.php         # Recipient views document via token
│   └── assets/
│       └── style.css    # Global styles
├── lib/
│   ├── bootstrap.php    # DB connection, helpers (audit_log, current_staff, h, random_token)
│   └── layout.php       # HTML header/footer rendering
├── tests/
│   └── test.php         # Minimal test harness (seeds DB, runs assertions)
├── schema.sql           # Canonical schema (4 tables)
├── seed.php             # Drops + recreates db.sqlite with sample data
├── Dockerfile           # PHP 8.3 + pdo_sqlite
├── docker-compose.yml   # Single service, mounts repo, exposes :8000
└── docs/
    └── ARCHITECTURE.md  # This file
```

## Entry Points

| URL | File | Auth | Purpose |
|-----|------|------|---------|
| `/` | `public/index.php` | — | 302 redirect to `/admin.php` |
| `/admin.php` | `public/admin.php` | Staff (hardcoded user #1) | Create documents, view document list |
| `/share.php?doc={id}` | `public/share.php` | Staff | Generate share link for document |
| `/view.php?token={hex}` | `public/view.php` | Public (token-gated) | Recipient views shared document |

## Database Schema

Four tables in `schema.sql`:

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
| created_at | TEXT | Default `datetime('now')` |

### `shares`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| document_id | INTEGER FK → documents.id | |
| token | TEXT UNIQUE | 32-char hex (16 random bytes) |
| recipient_email | TEXT | |
| created_at | TEXT | Default `datetime('now')` |

### `audit_log`
| Column | Type | Notes |
|--------|------|-------|
| id | INTEGER PK | Auto-increment |
| staff_id | INTEGER | Nullable |
| action | TEXT | e.g. "create" |
| entity_type | TEXT | e.g. "document", "share" |
| entity_id | INTEGER | |
| details | TEXT | JSON blob |
| created_at | TEXT | Default `datetime('now')` |

## Key APIs / Internal Functions

All in `lib/bootstrap.php`:

| Function | Purpose |
|----------|---------|
| `db(): PDO` | Singleton SQLite connection with FK enforcement |
| `current_staff(): array` | Returns staff row #1 (no real auth) |
| `audit_log(action, entity_type, entity_id, details)` | Inserts audit trail entry |
| `random_token(bytes=16): string` | Generates hex token for share links |
| `h(string): string` | HTML-escape helper |

Layout helpers in `lib/layout.php`:

| Function | Purpose |
|----------|---------|
| `render_header(title, staff?)` | Outputs `<html>` through `<main>` open |
| `render_footer()` | Closes `</main></body></html>` |

## Data Flow

```
Staff creates document:
  POST /admin.php → INSERT documents → audit_log("create","document") → redirect

Staff generates share link:
  GET /share.php?doc=N → show form
  POST /share.php?doc=N → INSERT shares (with random token) → audit_log("create","share") → show link

Recipient views document:
  GET /view.php?token=abc123 → SELECT shares JOIN documents → render or 404
```

## Authentication Model

None. `current_staff()` always returns staff row #1 ("Freddy Folio"). No login, no sessions. Recipient access is gated solely by knowledge of the share token.

## Runtime Lifecycle

1. `docker compose up` builds image (PHP 8.3 + pdo_sqlite)
2. Container runs `php seed.php` — drops and recreates `db.sqlite` from `schema.sql`, inserts sample data
3. Container starts `php -S 0.0.0.0:8000 -t public/`
4. Host volume mount means file edits reflect immediately on refresh

## Testing

`tests/test.php` — custom micro-harness. Re-seeds DB at start, runs test closures, reports pass/fail counts. Execute via:

```
docker compose exec app php tests/test.php
```

## Design Notes

- No migration system exists yet — `schema.sql` is applied fresh each startup via `seed.php`
- No CSRF protection on forms
- Timezone hardcoded to `America/Chicago` in bootstrap
- Share tokens are 128-bit random hex — unguessable but not human-readable
- All queries use prepared statements (no SQL injection)
- Output escaping via `h()` (no XSS)
