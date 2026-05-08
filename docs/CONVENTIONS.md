# Project Conventions

Rules that must hold across all changes

## Database

- **Schema changes go through migration files only.** Never edit `schema.sql` directly for new work. Add numbered files in `migrations/` (e.g. `001_description.sql`).
- **`schema.sql` is the base schema.** It represents the initial state. Migrations build on top of it.
- **Migration files are immutable once applied.** Never edit a shipped migration. Write a new one to correct it.
- **Migrations must fail loudly.** PDO ERRMODE_EXCEPTION is set — a bad migration halts the process. No silent skips.
- **All queries use prepared statements.** No string interpolation into SQL. No exceptions.
- **Foreign keys are enforced.** `PRAGMA foreign_keys = ON` is set at connection time. Respect referential integrity.
- **Timestamps stored as UTC.** SQLite `datetime('now')` returns UTC. PHP display can convert, but storage is always UTC.

## PHP

- **No frameworks, no Composer.** This is plain PHP. Don't introduce autoloaders or dependency managers.
- **`require_once` for files included from multiple entry points.** `require` for single-path includes.
- **All user-facing output escaped with `h()`.** No raw `echo $_POST[...]` or `echo $row[...]` in HTML context.
- **Entry points live in `public/`.** Everything outside `public/` is not web-accessible (enforced by `-t public/` server flag).
- **Helpers live in `lib/`.** Shared functions go here, not duplicated across entry points.
- **`current_staff()` is the auth boundary.** All staff-facing actions go through this. When real auth is added, this is the single point of change.

## Audit Trail

- **All state-changing actions are logged.** Document creation, share creation, schedule changes — anything that mutates data gets an `audit_log()` call.
- **Audit log format:** `audit_log(action_verb, entity_type, entity_id, details_array)`.
- **Never delete audit log entries.** Append-only.

## Testing

- **Every feature has at least one test.** Follows pattern in `tests/test.php`.
- **Three test categories per feature:**
  1. **Happy path** — feature works as designed with valid input.
  2. **Sad path** — invalid input, missing data, expected failures (404, empty results, malformed tokens).
  3. **Edge cases** — boundary conditions (exact-boundary timestamps, collisions, single-char input, empty sets).
- **One test file per feature.** Named `tests/test_<feature>.php`. Keeps concerns isolated.
- **Tests re-seed before running.** Each test run starts from known state.
- **Tests clean up after themselves.** Temp files, test DB rows removed at end of each test.
- **Tests assert DB state, not HTTP responses.** Current harness doesn't do HTTP-level testing.
- **Mock only at system boundaries.** DB is the system under test — don't mock it. Mock external APIs, filesystem where appropriate. If the thing being tested IS the integration, test it directly.

## URLs & Routing

- **Public (recipient) pages are token-gated.** Knowledge of token = authorization. No session required.
- **Staff pages assume authenticated user.** Currently hardcoded, but treat as if auth exists.
- **No URL rewriting.** Direct `.php` file mapping. Router not needed at this scale.

## Security

- **Prepared statements for all DB access.** Prevents SQL injection.
- **`h()` on all output.** Prevents XSS.
- **`random_token()` uses `random_bytes()`.** Cryptographically secure. Don't replace with `rand()` or `uniqid()`.
- **Secrets stay out of repo.** `.gitignore` covers `db.sqlite`. No `.env` files with credentials.

## Docker / Dev Flow

- **`docker compose up` must work from fresh clone.** No manual setup steps.
- **`seed.php` is destructive but idempotent.** Drops and recreates DB from scratch. Safe to re-run any number of times, always produces same known state. Migrations run after base schema.
- **Volume mount means live reload.** No rebuild needed for PHP/CSS changes.
- **Tests run inside container:** `docker compose exec app php tests/test.php`.
- **Lint before commit:** `php -l <file>` on changed PHP files to catch parse errors. Tests may pass while the actual app file is broken (tests reimplement logic separately).

## Idempotency

- **Seed is idempotent.** Run it 1x or 100x, same result.
- **Migrations are idempotent at the system level.** The `migrations` table prevents re-application. Individual SQL files don't need `IF NOT EXISTS` guards — the runner handles it.
- **Token generation is NOT idempotent.** Each call to `random_token()` produces a new value. This is correct — share links must be unique.

## Style

- **No comments explaining what.** Code should be self-explanatory. Comment only non-obvious *why*.
- **Minimal abstraction.** Don't add layers until repetition demands it.
- **Consistent formatting.** 4-space indent in PHP. Follow existing patterns.
