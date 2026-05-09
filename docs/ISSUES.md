# Gaps and Issues

Prioritized project gaps and issues found during initial exploration, ordered by impact. Status column tracks what was addressed during this work.

| # | Gap / issue | Impact | Recommendation | Status |
|---|-------------|--------|----------------|--------|
| 1 | No migration system | High: blocks any feature requiring schema changes | Add a small migration runner before the first schema-changing feature. Keep it simple and compatible with fresh `docker compose up`. | |
| 2 | PHP/SQLite timezone mismatch | High: scheduled publishing could be off by hours | Establish a consistent time model before comparing publish times. Prefer UTC because SQLite `datetime('now')` is UTC. | |
| 3 | README says links are "one-time," but tokens are reusable | Medium/high product ambiguity | Treat this as a product decision before changing behavior. Do not accidentally redefine the access model while building unrelated features. | |
| 4 | No real auth or authorization | High in production, acceptable in the starter app | Treat as production hardening unless a requested feature directly depends on staff identity or permissions. | |
| 5 | No CSRF protection on POST forms | Medium security gap | Treat as production hardening unless the feature materially expands mutating forms or security scope. | |
| 6 | Test harness is too narrow | Medium: reviewers would only see one passing test | Expand the runner when adding feature suites so one command runs all tests. | |
| 7 | Tests are mostly DB/helper-level, not HTTP-level | Medium residual risk | Add route-level or boundary tests when rendered behavior, status codes, or request handling are the main risk. | |
| 8 | `h(string $s)` is not null-safe | Medium latent PHP 8 crash risk | Fix if touching nullable data paths or rendering nullable fields. | ✅ Fixed in this commit — `h()` now accepts `?string` with `?? ''` coalescing |
| 9 | `SELECT *` on joins can silently shadow columns | Medium latent data bug | Prefer explicit columns when touching joined queries, especially as schema changes add new fields. | |
| 10 | Readable-ID migration would need backfill for existing rows | Medium in a real migration, lower for fresh seeded review flow | If readable IDs are added, decide whether fresh-seed behavior is enough or whether existing documents need a backfill. | ✅ Fixed — `migrations/003_backfill_readable_ids.sql` populates `'doc-N'` for any pre-existing rows; upgrade-path test in `tests/test_readable_id.php` verifies legacy rows are shareable after migration |
| 11 | Multi-statement migrations need a parsing strategy | Low/medium for complex migrations | A naive line/statement split is fine for simple ALTERs and CREATEs; complex SQL like procedures or trigger bodies would need a more robust parser. | |
| 12 | Recipient email is visible to anyone with a valid share token | Medium privacy concern | Preserve intentionally or hide/minimize if privacy is in scope. | |
| 13 | Input validation is minimal | Low/medium | Add targeted validation where the feature creates correctness or privacy risk; avoid broad validation rewrites. | |
| 14 | No indexes on common lookup/join columns beyond unique tokens | Low at this dataset size | Add indexes only if the feature adds lookup paths that need them at expected scale. | |
| 15 | No pagination on admin document list | Low at this scale | Search may address findability; add pagination only if list scale becomes part of the requirement. | |
| 16 | No structured error handling or custom 500 page | Low for the exercise | Leave unless feature work makes error handling user-visible or hard to debug. | |
| 17 | SQLite lacks WAL/busy timeout tuning | Low for this single-user demo | Add before concurrent production use, not as feature prerequisite. | |
| 18 | Audit helper re-queries current staff on every write | Low performance issue | Accept for this scale; pass staff ID explicitly only if audit volume or query count matters. | |
| 19 | Output buffering is not used | Low polish/resilience issue | Leave unless rendering errors become a practical problem during feature work. | |
