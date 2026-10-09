# P4.5 completion notes (executor anthropic/claude-opus-5-5/high, session 049e8860)

Status: implementation complete; **owner run pending** for the long checks (owner instruction
2026-10-09: «длинные прогоны в этой сессии не выполняй… Я сам запущу»).

## Checks

| Id | Where | Result |
|---|---|---|
| V1 | `packages/documents`: `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Collaboration tests/Operations tests/Arch` | **exit 0, 213 passed** (`V1-output.txt`) |
| V1-callers | `tests/Feature/MiddlewareStacksTest.php tests/Configuration/DocumentsConfigTest.php tests/Document/CollaborationFeatureGateTest.php tests/Document/DocumentStateVersionTest.php` | exit 0 (focused runs in session) |
| V2 | PG `tests/Concurrency/CollaborationFenceRaceTest.php` | kind=impact → barrier P4.7 (not run) |
| V3 | `hocuspocus-server`: `npm install && npm ci && npm run check && npm test` | **owner run** — new deps not installed here; lockfile is agent-denied. In session: `npm run check` equivalents (`node --check`) ok; dependency-free tests `webhook/security/config` 30/30 pass |
| V4 | `packages/vue`: `npm run typecheck && npm test` | **exit 0, 125 passed** (`V4-output.txt`) |
| V5 | `bash scripts/collab-e2e.sh` | **owner run** (real processes); log to `artifacts/P4.5/collab-e2e.log` |
| V80 | `packages/documents`: `pint --test` (changed files: passed) and `phpstan analyse --memory-limit=512M --no-progress` | Pint passed on every changed PHP file; **PHPStan: owner run**. Negative controls NC1/NC2 executed (`negative-controls.md`), NC3/NC4 handed off |
| V81 | `python3 plans/…/artifacts/coverage/opus-coverage.py check` | **exit 0**, 1438 units, 0 violations (`V81-output.txt`) |

## Invariant self-check (P4-invariants)

- **P4-I5 storage fence** — `PersistCollaborationStateAction` compares the room epoch with
  `collab_epoch` after T(shared) → N → D FOR UPDATE and passes `expectedEpoch` to
  `DocumentStateWriter` (which re-checks); restore/replace move the epoch inside the writer under
  the same D lock. HTTP: HubProtocolTest V12; locked write: "fences a past epoch inside the locked
  write itself" (NC1 red→green); PG interleaving: CollaborationFenceRaceTest (P4.7).
- **P4-I6 seed once per epoch** — `seed=true` requires `yjs_state IS NULL` on the locked row →
  `409 collaboration_seed_conflict` (NC2 red→green); PG race in CollaborationFenceRaceTest.
- **P4-I7 ro never writes** — hub: connection `readOnly`, no store under a ro context
  (readonly.test.js); backend: `mode=ro` token or no live Edit → 403 (HubProtocolTest, webhook test).
- **P4-I8 sequence untouched** — collaboration writes go only through `DocumentStateWriter`
  (no `NodeSequence` call; `touch` only); unchanged by this item.
- **Lock order T → N → D** — the Action and restore keep `lockTreeShared` → `TreeGuard::lock` →
  `lockForNode`; `CollaborationHub::open()` reads without locks (pre-check only, the fence is the
  locked write).
- **Interfering pairs** — restore ‖ store(old epoch): serialized on D, store after restore 409;
  purge/trash ‖ store: locked node re-read, trashed/purged → 404 → hub closes the room (4404);
  rename ‖ store: independent validators (unchanged).

## Owner-run commands (exact)

```bash
cd hocuspocus-server && npm install && npm ci && npm run check && npm test        # V3 (+ commit package-lock.json)
cd packages/vue && npm run typecheck && npm test                                  # V4 (green here)
bash scripts/collab-e2e.sh                                                        # V5
cd packages/documents && ../../scripts/php84 vendor/bin/pint --test && ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress   # V80
```

Then NC3/NC4 per `negative-controls.md`, `plan.py finish … --check V1=0 V3=0 V4=0 V5=0 V80=0 V81=0`.

## Risk

concurrency + data-integrity + security boundary (hub) and public API (session JSON, driver
contract, DocumentsConfig, hub endpoints). Recommend `task:review` over the P4.5 diff after the
owner run is green.
