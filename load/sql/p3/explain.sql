-- P3.1 — disposable read-only EXPLAIN template (DML actual plans are P3.3 + disposable clone marker).
-- Caller must set statement_timeout/lock_timeout and run only when manifest.disposable_db is true.
\set ON_ERROR_STOP on

-- Example fingerprint probe (no user data): planner shape only.
EXPLAIN (FORMAT JSON, SETTINGS)
SELECT 1 AS probe;
