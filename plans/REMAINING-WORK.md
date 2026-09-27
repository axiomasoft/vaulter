# Remaining work — canonical execution map

**Updated:** 2026-09-27 after B07. **Scope:** the remaining product, evidence, mutation and owner work evidenced by VLT-LOAD and the archived plans. This file routes work; it does not amend journals, raw runs, SLO thresholds or historical verdicts.

**Where execution stopped:** B07 failed and did **not** admit B08. B07 found two Major defects in the prepared R05 P5 capture/verdict path and confirmed that R04/R05/R06/R08 measurements and the current cold R07 >=95 proof are absent. The next launch is therefore **B05-R: correct R05 for B07-01/02**. Do not rerun R02, R18, R19, R14, B02, or the already prepared code slices merely because they appear below.

**Current launch:** `R05` is a load-harness implementation slice, owned by GPT-6 Sol. It must correct both B07 findings, write `remaining-evidence/R05.md`, update this map, and stop. It does not run a live cell. R06 is the next *exclusive measurement* only after that correction is recorded.

## 1. Evidence rules and fixed facts

Read the applicable finding, SLO contract, gate contract, raw run and evidence carrier before a row. The latest conclusion for an audit replaces an older conclusion in that audit; it does not rewrite a product verdict outside its stated scope.

- VLT-LOAD is terminal `Done with deviations`; its P8 receipt is lifecycle evidence, not a product-wide GREEN. Do not execute its old `Pn.m` items. AUDIT-VLT and VLT-HSEAM are archived/superseded; their live Layout-v2 previews are quarantined and are not launch queues.
- B02's last section closes **B02-01/02 in its scoped runtime/verifier scope**. It does not create a new full P6 runtime or an all-SLO PASS. R09's stored P6 evidence remains the authority for the measured SLO-58/64 GREEN and SLO-61 RED; the historical failed-job identities remain unrecoverable.
- B07 is the latest audit of R02–R08: it failed. B08 has no admission. B07-01 and B07-02 are open R05 implementation defects; B07-03–05 are missing-evidence gates, not newly proved product defects.
- R14 is a completed measurement, not an unfinished measurement: CAS integrity/memory and GC delete/RSS were GREEN; **GC scan throughput and the 1,000-row batch cap were RED**. That result creates R21/R22/R23 below. Do not call R14 unperformed.
- R18 (media mutation 95.33% PG+SQLite union) and R19 (filament mutation 95.51% SQLite) are complete on their frozen sources. A media source change in R21 invalidates R18 only for that changed media source and routes to R22; it does not erase the recorded result.
- P4 L-01's measured streaming bytes/memory result remains historical evidence. Its separate D11 mutation claim is unproved because it used `--parallel`; R02 and the package-specific mutation rows, not a rerun of L-01, route that defect.
- No smoke, S0 probe, one-row cardinality diagnostic, terminal task receipt, passing unit suite, offline control, or test-only overlay is an authoritative tier-S/M/L product result. A completed measurement may be RED; record that result and follow its route.
- Mutation proof uses a cold cache, the declared database drivers, real skip/timeout denominator and no `--parallel`. The target is >=95; no configured minimum may be lowered to make a gate pass.

## 2. Obligation ledger

Every unresolved finding has one next route. `Complete` means the stated scope is evidenced; it never implies a wider claim. `Measurement` rows reserve the shared stand exclusively. A source digest/run ID and raw-artifact link are mandatory in the carrier.

| Source / finding | Current state | R | Executor | Next concrete action | Closure criterion / evidence |
|:--|:--|:--|:--|:--|:--|
| L-04 core retry | implementation and bounded R09 retry proof complete | R01 | complete | preserve [R01](remaining-evidence/R01.md) and R09 | no relaunch; R26 only supplies any later full-P6 re-verdict |
| P4 L-01 streaming / D11 | streaming measurement retained; `--parallel` mutation conclusion invalid | R02, R07, R16, R17, R22 | routed | preserve streaming raw evidence; use package-specific cold routes | no aggregate/parallel mutation claim; each package's >=95 criterion controls |
| F-P2.8-1/2/3; SLO-40/43/49/52 | code prepared; historical tier-S throughput RED remains unattributed | R03 | GPT-6 Sol | exclusive full-window class-A/B run and attribution | immutable full window, materialized tagged metrics, adequate samples; retain or route any RED |
| L-P3-01, L-P3-03 | code verified; old trace empty and 6 plans rejected | R04 | GPT-6 Sol | exclusive P3 capture | nonempty flushed trace, bindings, actual ANALYZE, top-20/N+1 result; no claim from zero rows |
| L-P3-04/L-05 endpoint scan | scoped R10 evidence complete; M/L and 24-hour claims unevaluated | R26 | GPT-6 Sol | include in current P6 full matrix only if its source remains applicable | tier-specific verdicts, or explicit unevaluated M/L/24-hour rows in R26/R20 |
| L-P5-01/02/04; **B07-01** | R05 code defect: live-patch overlap launches no sweeper | R05 | GPT-6 Sol | make the named live-patch surface run a real concurrent sweep/PATCH with session identity, overlap and final-state capture | negative controls reject no-sweep/no-overlap; affected live cells and Astra re-review prove the path |
| L-P5-01/02/04; **B07-02** | R05 code defect: declared N is not checked against observed actor participation | R05 | GPT-6 Sol | record observed actor IDs and completed/aborted iterations; reject missing actors and setup-only success | negative controls for a missing actor/incomplete iteration; affected cells and Astra re-review |
| B07-03 P5 portion | no valid R05 live evidence | R05 | Grok 4.7 | after R05 code correction, exclusively run affected P5 cells | raw per-actor/status/Retry-After/lock/pool/overlap evidence; 429-only remains unconfirmed |
| L-P5-03, L-02; B07-04 | capture code exists; dirty-20k cause unknown; SLO-70 historical RED | R06 | Grok 4.7 | exclusively reproduce disposable dirty-20k with stderr, exit, PHP peak and batch/progress rows | measured failure classification; no guessed drive fix |
| drive L-02, SLO-70/72 | waiting for R06 cause; no R08 carrier | R08 | GPT-6 Sol then Grok 4.7 | implement the smallest `packages/drive` fix justified by R06; then exclusive tier-S rerun | focused/package evidence plus SLO-70 result (GREEN or RED); current cold R07 >=95 before accepting final drive source |
| drive D11 / VLT-MUT P2; B07-05 | tests prepared; cold current-source PG score absent; workflow still min 43 | R07 | GPT-5.6 Sol | after R08 freezes drive source, exclusive cold sequential PG mutation and a demonstrated 95 gate failure path | >=95 with real denominator and no `--parallel`; otherwise add tests and rerun R07 |
| P6 L-P6-01…04; **B02-01/02** | scoped B02 audit closed; R09 raw/verifier evidence preserved; not a new full-P6 acceptance | R09 | complete | preserve [R09](remaining-evidence/R09.md) and [B02](remaining-evidence/B02-audit.md) | no re-execution without a source-changing reason |
| P6 concurrent duplicate: 2 dispatch / 2 HTTP | product residual, explicitly unproved exactly-once | R24 | GPT-6 Sol | `packages/core` reproduce and correct duplicate delivery semantics with concurrent behavior tests | a source digest, regression test and independent audit; then R26 product run has no duplicate residual or records RED |
| P6 recovery RED, sticky historical failed jobs, SLO-61 RED | current P6 product failures; original two historical job classes unrecoverable | R25 | GPT-6 Sol | classify each current recovery RED cell against real queue/sink semantics; correct only load-harness defects in `load/`, and open a new package-scoped R if product behavior is at fault | carrier maps every red cell to harness defect, package R, or retained RED; never invent historical job identities |
| P6 current full verdict; SLO-58…64 and endpoint M/L | pending after R24/R25; R09's SLO-61 stays RED until superseded by this run | R26 | Grok 4.7 | exclusive frozen-source complete P6 matrix | immutable verified run, SLO table by tier, duplicate/recovery and failed-job deltas; RED remains routed, M/L absent is unevaluated |
| P7 L-P7-01…05 | R11 code prepared; green `/ready` baseline and live metrics absent | R11 | GPT-6 Sol | exclusively prove baseline readiness/metrics using real named operations and seeded joins | baseline evidence plus actual fault paths; code preparation alone does not close it |
| P7 authoritative recovery | waiting | R12 | Grok 4.7 | after R11, R08 and any R24/R25-relevant changes, run full tier-S P7 matrix | immutable run, real first/retry attempts, DB<->object joins, per-cell verdicts |
| L-06; L-P3-02/05/06/07 | unmeasured 1,000-anchor/tree profile | R13 | Grok 4.7 | exclusive scale measurement after B07 passes and R04/R05 evidence is valid | 1,000-anchor series, actual SQL/CTE/tree timings; separate package fix decision if needed |
| L-03, L-P4-01; SLO-76…78 | R14 measured: CAS/GC parts GREEN; GC scan throughput + batch cap RED | R21 | GPT-6 Sol | `packages/media` implement bounded GC scan/batch behavior for the observed RED | package tests prove cap/progress; no SLO claim from code checks |
| R21 source change / media D11 | R18 complete only for pre-R21 source | R22 | GPT-5.6 Sol | exclusive cold PG+SQLite mutation-ID union on R21 source | >=95 and enforced regression-failing gate; otherwise return to R21 |
| R14 RED cells after R21 | rerun required; R14 historical result remains | R23 | Grok 4.7 | exclusive GC/CAS measurement on R21 source | scan throughput and batch cap measured against unchanged SLO; retain GREEN/RED by metric |
| L-07 | unconfirmed; old P5 was 429-only | R15 | Grok 4.7 | after valid R05 evidence, run true advisory-key collision with independent actors | confirmed, disproved, or explicitly unconfirmed from raw lock/HTTP evidence |
| core D11 / VLT-MUT P0.2/P1.1/P3 | R16 code prepared and suites green; cold union missing | R16 | GPT-5.6 Sol | exclusive cold sequential PG+SQLite ID-union campaign | >=95 with workflow failure control; otherwise return to R16 test work |
| documents D11 / VLT-MUT P0.2/P1.1/P4 | R17 code prepared and PG suite green; cold PG missing | R17 | GPT-5.6 Sol | exclusive cold sequential PG campaign | >=95 with workflow failure control; otherwise return to R17 test work |
| media D11 / VLT-MUT P5 | R18 complete at 95.33% on frozen pre-R21 source | R22 if R21 changes source | GPT-5.6 Sol | do not rerun unless R21 changes media source | R18 remains closure evidence until then |
| filament D11 / VLT-MUT P6 | R19 complete at 95.51% | complete | — | no rerun | [R19](remaining-evidence/R19.md) |
| L-08 scheduler; archived release/CI residuals | external/current-state questions, not code evidence | R20 | Grok 4.6 | read current deployment/CI/release state and prepare owner handoff | current fact table plus explicit owner decisions; no publish, billing or archive mutation |

## 3. Work states and dependencies

`implemented` and `code prepared` are not measurement completion. The following state list is the source of truth for launch admission.

| R | State now | Must happen before it can close / unlock |
|:--|:--|:--|
| R01 | complete implementation; historical R01 run rejected; R09 supplies the accepted scoped retry evidence | no relaunch |
| R02 | complete baseline inventory | no relaunch |
| R03 | code prepared; measurement pending | full-window R03 measurement |
| R04 | code verified; measurement pending | R04 capture; B07 re-audit needs valid R04 evidence |
| R05 | **implementation correction required** for B07-01/02; measurement pending | R05 code correction -> R05 live cells -> B07 re-audit |
| R06 | capture code complete; measurement pending | exclusive dirty-20k classification -> R08 |
| R07 | test code prepared; cold PG >=95 pending | R08 final source -> cold R07 |
| R08 | waiting | R06 cause -> R08 code/checks -> tier-S run -> R07 requalification |
| R09 | B02-01/02 scoped audit closed; historical product verdicts retained | no relaunch; feeds R24–R26 |
| R10 | scoped endpoint implementation/evidence complete | M/L/24-hour claims route through R26/R20, not R10 rerun |
| R11 | code prepared; live baseline pending | R05-valid fixtures and R08 path -> R11 baseline |
| R12 | waiting | R11, R08, R24/R25-relevant changes and B14 audit |
| R13 | waiting | valid R04/R05 + passing B07 |
| R14 | measured, mixed; two RED cells | R21 -> R22 -> R23; do not relaunch old R14 |
| R15 | waiting | valid R05 evidence |
| R16/R17 | code prepared; cold proof pending | exclusive campaigns; failures return to the same R |
| R18/R19 | complete | R18 only requalifies through R22 if R21 changes media |
| R20 | waiting | evidence/reconciliation; it cannot accept product risk |
| R21 | new, waiting | implement media GC correction -> R22 |
| R22 | new, waiting | R21 source -> cold media requalification -> R23 |
| R23 | new, waiting | R21/R22 source and exclusive stand -> measured media disposition |
| R24 | new, waiting | core duplicate repair -> R26 |
| R25 | new, waiting | P6 recovery classification -> package R if required -> R26 |
| R26 | new, waiting | R24/R25 and frozen source -> current P6 matrix |

## 4. Ordered launch route

The shared stand is exclusive for mutation, load, chaos, DB reset and cold-cache work. Code tasks may prepare one package or `load/`; they do not run a heavy measurement. Every command is one R only. Terra's sole role was editing this map; it does not replace any executor below.

Use this exact bounded launcher for every listed row, replacing `RNN` once only:

```text
task:dev plans/REMAINING-WORK.md RNN
```

### Code route (no live tier-S, chaos, mutation or load campaign)

1. **B05-R / R05 — GPT-6 Sol.** Read B07-01/02, repair only `load/` capture/verdict logic, add offline controls, save R05 carrier and map state. If an overlap or actor control fails, remain in R05.

   ```text
   task:dev plans/REMAINING-WORK.md R05
   ```

2. **B06-R / R08 — GPT-6 Sol, only after R06 has a measured cause.** Implement only the causal drive fix. If R06 identifies no product defect, record that and do not manufacture R08 code.

   ```text
   task:dev plans/REMAINING-WORK.md R08
   ```

3. **B18 / R21 — GPT-6 Sol.** Repair the two R14 media RED metrics in `packages/media` with package tests. It is a new scoped implementation, not an R14 rerun.

   ```text
   task:dev plans/REMAINING-WORK.md R21
   ```

4. **B19 / R24, then R25 — GPT-6 Sol.** First correct the core duplicate-delivery residual; then classify every P6 recovery RED without rewriting old raw evidence. A package defect discovered by R25 gets its own next free R before R26.

   ```text
   task:dev plans/REMAINING-WORK.md R24
   task:dev plans/REMAINING-WORK.md R25
   ```

R11 may receive no additional code work until valid R05 fixtures and the R08 path exist. R16/R17 have prepared code and wait for their exclusive cold runs; R18/R19 are not code launches.

### Exclusive measurement route

Run these in the stated dependency order, each with an unused run ID, source/stand digest, cleanup/readback, exact command and raw evidence. A RED result completes its measurement row but follows the return edge shown here.

1. **B05-M / R06 — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R06`; inputs R06 capture code and P5 dirty artifacts; reproduce dirty-20k. Success is a classified cause, not a GREEN. Cause -> R08; inconclusive run -> improve R06 capture and repeat R06.
2. **B05-M / R05 live cells — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R05`; inputs corrected R05 and historical P5 artifacts; run overlap, actor, status, lock and pool cells. Valid evidence -> B07-R; failed controls -> R05.
3. **R03 then R04 — GPT-6 Sol:** `task:dev plans/REMAINING-WORK.md R03`, then `task:dev plans/REMAINING-WORK.md R04`; full-window throughput then P3 trace/plan evidence. Missing trace/early abort -> the respective R. Both valid plus R05 evidence -> B07-R.
4. **B07-R — GPT-6 Astra:** exact prompt: `Проведи B07-R по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B07-audit.md; не меняй код, raw evidence или статусы R.` GREEN admission -> B08; any finding -> its named R, never an automatic fix.
5. **B08 / R13 then R15 — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R13`, then `task:dev plans/REMAINING-WORK.md R15`; only after B07 admits and R04/R05 are valid. R13 RED may create a separate core/drive fix; R15 must retain `unconfirmed` if collision is not observed.
6. **Mutation campaign — GPT-5.6 Sol:** run `task:dev plans/REMAINING-WORK.md R16`, `R17`, and (after R08) `R07` as separate cold jobs. Each miss returns to that package R. Run `task:dev plans/REMAINING-WORK.md R22` after R21 before treating media mutation as current-source proof. R18/R19 are evidence inputs, not jobs.
7. **B18-M / R23 — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R23` after R21/R22; remeasure only the R14 GC/CAS matrix. RED returns to R21; GREEN does not alter old R14 raw evidence.
8. **B20 / R26 — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R26` after R24/R25 and any R25 package repair; run the frozen-source full P6 matrix. SLO-61 may remain RED; that is a result routed to R20/owner, never silently GREEN.
9. **B13-M / R11 then B14 — GPT-6 Sol / GPT-6 Astra:** `task:dev plans/REMAINING-WORK.md R11`, then audit prompt `Проведи B14 по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B14-audit.md.` Prove P7 live baseline first; audit findings return to their R.
10. **B15 / R12 — Grok 4.7:** `task:dev plans/REMAINING-WORK.md R12` only after B14 has no finding that invalidates the run. Any recovery RED goes to the concrete package/harness R identified in the carrier before another R12 run.
11. **B16 / R20 then B17 — Grok 4.6 / GPT-6 Astra:** `task:dev plans/REMAINING-WORK.md R20`, then prompt `Проведи B17 по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B17-audit.md.` Neither batch accepts risk, publishes, changes deployment, or closes VLT-LOAD.

## 5. Batch routing and audit boundaries

| Batch | State | Scope / admission | Executor |
|:--|:--|:--|:--|
| B01–B04, B06, B09–B12 | completed code/evidence stages | retain their carriers; no repeat queue | recorded executor |
| B02 | latest scoped audit closed | B02-01/02 only; does not promote P6/SLO | GPT-6 Astra |
| B05-R / B05-M | active | R05 correction -> R06 and R05 measurements | GPT-6 Sol / Grok 4.7 |
| B07-R | waiting | valid R03/R04/R05/R06/R08 evidence and cold R07 >=95 | GPT-6 Astra |
| B08 | blocked | B07-R admission plus valid R04/R05 | Grok 4.7 |
| B13-M / B14 / B15 | waiting | R11 baseline; B14 validates mutation/P7 before R12 | GPT-6 Sol / Astra / Grok 4.7 |
| B16 / B17 | waiting | completed applicable evidence; owner decisions remain external | Grok 4.6 / GPT-6 Astra |
| B18–B20 | new | R21/R22/R23 media route; R24/R25/R26 P6 route | Sol / GPT-5.6 Sol / Grok 4.7 |

Auditors save only their audit report. The implementation or measurement owner must then perform a separate map-sync step: read that report, update the affected R state and ledger row, and name the next admitted launch. An audit never marks its own findings fixed.

## 6. Archived and owner-only obligations

These are deliberately not reopened as code work. R20 must report their current factual state and give the owner the indicated decision; it must not make the decision.

| Source | Current disposition | Owner decision required |
|:--|:--|:--|
| VLT-REMED handoff: 78.6% < 80% release-readiness | historical RED; current value must be rechecked in R20 | fund/authorize a coverage improvement or explicitly accept the current residual |
| VLT-CANON handoff: private/free GitHub cannot enforce required checks | external constraint, not a repository fix | choose GitHub Team/Pro or make the repository public, or accept non-enforcement |
| VLT-REL D9: split repos, token and Packagist registration absent | release tag exists; publication is deferred | provision split/Packagist and explicitly authorize publication/retry, or keep deferred |
| VLT-CANON ADR-0018 mixed `format` values | explicitly accepted limitation | no action unless owner opens a separately scoped migration decision |
| VLT-CANON docs/reactivity/README drift and VLT-REL D12 examples | bounded historical documentation debt | choose a separate docs task if desired; it is outside product proof completion |
| L-08 host scheduler | host ownership is documented, host execution is not proved | provide deployment evidence or explicitly accept the absence of host-scheduler proof |
| remaining product RED/unevaluated SLOs, including SLO-61 | no one may accept them through R20/B17 | after R26/R12 current evidence, accept each named deviation or authorize its repair |

## 7. Evidence and map-update contract

After every implementation or measurement slice, its executor must immediately:

1. write or extend only its `plans/remaining-evidence/RNN.md` carrier with revision, exact commands/exits, run ID, source/stand digest, raw links, observed GREEN/RED/unevaluated verdict and limitations;
2. update this map's ledger and state table with what closed, what remains, new R IDs needed by a newly identified package defect, and the single next admitted launch;
3. preserve raw evidence, SLO thresholds and historical verdicts. A correction never overwrites a rejected or RED artifact.

For an audit, the auditor writes only `Bxx-audit.md`; the subsequent map-sync is performed by the responsible executor. For a failed verification, return to the named corrective R in this map. Do not leave a generic `blocked` status when a code, measurement, re-audit, or owner-decision route is known.

## 8. Completion criteria

Vaulter is ready for an owner close decision only when the following are evidenced, not merely discussed:

- every ledger row is complete, explicitly retained RED/unevaluated with an owner disposition, or an accepted archival limitation;
- B07 and B14/B17 have no unresolved finding that invalidates a dependent run;
- current-source mutation proof is >=95 for drive, core, documents and media under the declared drivers; R18/R19 remain valid for their sources;
- the R21 media fix and R23 measurement resolve or explicitly retain the R14 throughput/batch-cap RED;
- R26 contains the current P6 matrix and explicitly reports duplicate delivery, recovery, failed-job deltas and SLO-61; R12 contains the current P7 recovery matrix;
- R20 publishes the current SLO-by-tier table, current CI/release/host facts and the owner-decision list. M/L or 24-hour gaps remain `unevaluated` unless actually measured;
- the owner, not an executor or audit, decides whether any remaining RED, unevaluated target, deployment constraint, required-check limitation, or deferred publication is acceptable and whether to close/archive VLT-LOAD.
