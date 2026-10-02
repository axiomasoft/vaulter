# P0.2 — PA01 owning repair checkpoint

Only ForkRaceTest.php changed. Four tests retained. The connection test now forces exactly one child to fail reconnect before ready, using atomic fopen(..., 'x'). Its sibling must return ForkRace\BarrierTimeout and no task may write its started marker. No scheduling delay and no harness source change.

- PostgreSQL serial repetitions: 5/5 exit 0; each 4 passed, 17 assertions, 0 skipped.
- SQLite: exit 0; 4 skipped.
- Pint of Concurrency and tests/Pest.php: exit 0.
- Barrier-only bypass: exit 1; only readiness test fails (BarrierTimeout replaced with null).
- Sequential control: exit 1; lost-update fails on [0,1] vs [0,0], readiness also fails.

Initial normal-repeat and mutant attempts overlapped on the same isolated test DB and caused migration errors. Their logs are retained but excluded. final-runs.json and serial/final logs are authoritative. All later DB checks were serialized under a file lock.

Unchanged media autoload and root PG selections reuse earlier proof; the sole affected Concurrency step was rerun five times with --ci. validation-provenance.json distinguishes reuse from fresh runs, retaining original timestamps. Hosted CI for changed candidate remains a phase gate.

D09 independent full review was attempted once: fresh context, safe mode, no tools, no persisted session, claude-sonnet-5-5. Claude Code returned session quota limit, reset 2026-10-02 13:20 Europe/Volgograd (10:20 UTC). No model usage or review verdict; E23 is missing. Raw response, prompt, source SHA and waiting-external gate are saved. Resume only when service availability is observed. Do not rerun passing checks for unchanged source SHA.

P0.2 remains In progress; PA01 is fixed locally but closure awaits review. P0.1 mutation scores, P0.3/P0.4 and unrelated .gitignore are preserved. No full suite, push or hosted dispatch in this repair.

write-site: pre_mutation: allow · pre_final: block:VALIDATION_RECEIPT_MISSING

Plan metadata validation: plan-lint exit 0, 0 errors / 0 warnings (plan-lint.txt). Previous audit-handoff frontier lint mismatch is resolved while P0.2 is now actually In progress. Local checkpoint commit is a real external-quota boundary, not an item terminal close.
