# Submission Notes
---

## What was built

- **Scheduled publishing** — `publish_at` on creation; recipients see 403 "not yet available" until then. Roundtrip date validation rejects invalid/overflow dates (`2026-02-30` doesn't silently become March 2).
- **Human-readable document IDs** — slug + 4 random alphanum (e.g. `welcome-packet-3k7x`). Complements share tokens, doesn't replace. Backfill migration covers pre-existing rows.
- **Search by title** — LIKE contains, case-insensitive, wildcards escaped, GET form with `×` clear button.

---

## Infrastructure

- Hand-rolled migration runner (`migrate.php`) — transaction-wrapped, per-statement execution, idempotent
- UTC standardized across PHP and SQLite
- Test runner auto-discovers all `tests/test_*.php` suites

---

## Key tradeoffs

| Choice | Why |
|---|---|
| Readable IDs **complement** tokens (don't replace) | Slug + 4 chars is guessable; no recipient auth. Tokens stay as access control. |
| Backfill via `'doc-' || id` SQL UPDATE | One line, no runner extension. Legacy rows get `doc-N`; new rows get full slugs. |
| LIKE `%query%` not FTS5 | Over-engineered for dozens of docs. |
| Reject invalid datetimes (not silent NULL) | Silent NULL would publish early — data leak. |
| 403 for scheduled, 404 for invalid | Token valid → 403; token invalid → 404. Semantic precision. |
| No audit log on search | Search is a read. |
| `parse_publish_at` extracted to `lib/` | Testability override of "minimal abstraction." Inline forces test duplication. Codified in CONVENTIONS.md. |
| Migration runner intentionally minimal (splits on `;`) | Sufficient for ALTER/CREATE INDEX/UPDATE. Trigger bodies / quoted `;` would warrant a smarter parser. |

---

## What's deferred

- Edit schedule after creation
- CSRF / sessions / real auth
- Pagination, FTS5
- HTTP-level integration tests (current tests validate logic, not route wiring)
- One-time link enforcement (pre-existing README/code mismatch)
- Recipient email visibility minimization

Full audit of pre-existing gaps and what was fixed vs deferred: [`docs/ISSUES.md`](ISSUES.md).
