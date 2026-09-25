# Remaining work — canonical execution map after VLT-LOAD P8

**Updated:** 2026-09-24
**Scope:** unfinished proof, product and mutation work already evidenced by the existing plans. This is a portfolio route, not a new Task executable plan and not an amendment to terminal journal/state.
**Current launch:** B04 / R03→R04 below. B01–B03 are evidenced (R10, R09/R10 audit if present, R02). See section 7 for owner-selected batches, models and full CLI commands. Owner deferred final VLT-LOAD close/archive.

## 1. Source precedence and plan inventory

Read the adopted [SLO contract](VLT-LOAD/slo.md) and [gate contract](VLT-LOAD/slo-gates.md), then the relevant phase finding/report and immutable raw artifacts. The [journal](VLT-LOAD/journal.jsonl), generated [status](VLT-LOAD/status.md) and P8 receipt establish lifecycle, while the reports establish product verdicts. Terminal GREEN execution is not product GREEN. The old `VLT-LOAD/plan.md` Meta `Paused By: D41` is a historical launch restriction, contradicted by later terminal journal/status; it is not a current admission gate. Do not change stable plan, generated views or append-only history merely to make portfolio routing look current.

| Carrier | Current disposition | Action |
|:--|:--|:--|
| VLT-LOAD | terminal 🟠 Done with deviations; P8 11/11 GREEN; closure/archive postponed by owner | no plan-exec Pn.m; use separate dev/evidence slices below |
| AUDIT-VLT | archived completed audit; live Layout-v2 copy is a migration preview with 0/30 status and stale P0.1 command | no rerun; quarantine preview routing until migration reconciles it with archive |
| VLT-HSEAM | superseded by VLT-CANON P3.3 / ADR-0018; live Layout-v2 copy is a migration preview | no P0.1 execution; quarantine preview routing |
| VLT-REMED, VLT-CANON, VLT-REL | archived terminal histories | preserve; reassess specific old release/mutation claims in R20 |
| VLT-MUT | moved under archive while its own plan still says In progress; no current journal/status/handoff for continuation | do not launch archive P0.2 directly; use the recovery route in section 3 |
| ROADMAP.md | optional future features, explicitly not scheduled | outside this completion queue |

The old VLT-MUT P0.1 reproduces a false 100% score when pest-plugin-mutate v4.0.1 receives `--parallel`. Current core/documents workflows still contain that flag; drive/media/filament workflows removed it but have intermediate limits. The P4 L-01 carrier repeated `--parallel` at v4.0.1, so its claim that D11 ≥95 is proved conflicts with the adopted mutation method. The P4 streaming run still proves its measured bytes and memory result. Keep these two claims separate.

## 2. Rules for every slice

- One package or the load harness per implementation slice; a cross-package gate is decomposed into package-specific work. Do not overwrite unrelated dirty files.
- Preserve approved SLO numerators, denominators, tiers, ceilings and error budgets. No stand fingerprint, S0 smoke run, single-row cardinality probe or tier-M/L-labelled diagnostic stands in for an authoritative tier-S/M/L product run.
- A finding can close as proved fixed, disproved with new evidence, or explicitly deferred with the actual RED/unevaluated verdict. Do not infer GREEN from a terminal Task item, clean unit tests, a collector repair, or a changed threshold.
- For mutation, remove the false `--parallel` path and measure on a cold cache with the required DB-driver coverage; do not lower a currently configured minimum to make CI green. Target ≥95 remains. A workflow below 95 is a partial guard, not release proof.
- Run focused tests first, then the affected package/harness gates and an independent concurrency/API review when relevant. Keep raw run identity, environment fingerprint, source digest and exact verifier outcome. If an authoritative stand is unavailable, record not remeasured.

## 3. Ordered slices and dependencies

The IDs below are stable portfolio work IDs, not VLT-LOAD Pn.m. `task:dev` can take this file plus one ID as input; it must execute only that ID. R01 has a dedicated detailed brief. Work in separate commits. "ready" means its input artifacts exist; it does not claim product GREEN or start work. R02, R03, R06 and the measurement-only R14 are independent of R01; downstream rows wait for their stated gates. After each slice, update its State, evidence link, commit and remaining product verdict in this map before launching a dependent row.

| ID | State | Scope and finding source | Needs | Exit evidence / disposition |
|:--|:--|:--|:--|:--|
| **R01** | implementation verified; tier-S SLO-64 measured GREEN via R09 | core retry claim L-04, P6.4; [result](remaining-evidence/R01.md), [accepted rerun](remaining-evidence/R09.md) | none; drive D11 does not cover this core file per P6 finding | bounded claim/overlap/rollback checked; original tier-S attempt remains rejected; R09 accepted rerun supplies full 5k drain and SLO-64 proof |
| **R02** | measured; [evidence](remaining-evidence/R02.md) | mutation proof recovery: old VLT-MUT P0/P1, P4 L-01 contradiction | none | inventory recorded; cold sequential scores: filament 59.26, media union 67.44, drive 48.90, core SQLite 63.25; documents PG and core PG unevaluated (red suites); P4 D11 100%/25.77s with `--parallel` is unproved |
| **R03** | code verified; live proof deferred; [evidence](remaining-evidence/R03.md) | load class A/B gate: F-P2.8-1, F-P2.8-2, F-P2.8-3 | P2.7/P2.8 reports | OP-tagged lab submetrics, dense sample audit and no premature abort implemented; historical tier-S throughput RED retained; new full-window S0/lab results unevaluated |
| **R04** | waiting | load P3 evidence L-P3-01, L-P3-03 | R03 for reusable summary, P3 raw run | DML EXPLAIN binding matrix and nonempty flushed SQL trace; reissued tier-S DB report |
| **R05** | waiting | load P5 evidence L-P5-01, L-P5-02, L-P5-04 and L-07 control | R03, P5 raw run | per-VU actors; actual collab status; real lock/pool samples; product collision verdict or explicit unconfirmed |
| **R06** | ready | load P5 sweeper diagnostics L-P5-03 / L-02 | P5 raw run | stderr/exit/PHP peak/batch rows captured for dirty-20k; cause identified, not guessed |
| **R07** | test code prepared; cold PG ≥95 pending; [evidence](remaining-evidence/R07.md); commit `b46b107` | drive mutation ≥95; old VLT-MUT P2 | R02 honest drive baseline (48.90%; D11 unproved) | cold PG score ≥95 without `--parallel`, suitable test coverage, regression-failing gate |
| **R08** | waiting | drive sweeper product L-02 | R06 cause and R07 D11 | bounded behavior if indicated by cause; race/retry tests and new tier-S SLO-70 run |
| **R09** | rerun verified; SLO-61 RED; [evidence](remaining-evidence/R09.md) | load P6 evidence L-P6-01, L-P6-02, L-P6-03, L-P6-04 | R01 product change is committed; P6 raw and rejected R01 run available | 118 valid attempts; SLO-58/64 measured GREEN, SLO-61 RED; full 10k/5k drain, UUID/fan-out joins and live scrape; original two failed-job classes unrecoverable after R01 deletion; concurrent duplicate emits 2 HTTP; worktree changes preserved, no mixed commit |
| **R10** | implementation verified; tier-S rerun accepted; [result](remaining-evidence/R10.md); commit `de65b56` | core endpoint scan L-05 / L-P3-04 | R09 reliable fan-out fixture, accepted | SQL event filter with bounded 100-row keyset pages; SQLite/PG fan-out and overlap tests; 118 valid tier-S attempts, SLO-58/64 GREEN and SLO-61 RED; M/L SLO-59/60 and 24-hour budget unevaluated; 1-row 200/1000 probes diagnostic only; concurrent duplicate 2 HTTP remains |
| **R11** | waiting | load P7 fidelity L-P7-01, L-P7-02, L-P7-03, L-P7-04, L-P7-05 | R05/R09 reusable fixtures, R08 for final sweeper path | real named HTTP/Artisan mutations, seeded package tables, measured promote, real ENOSPC retry, green baseline /ready and /metrics |
| **R12** | waiting | tier-S P7 rerun and recovery verdict | R11, relevant product fixes R01/R08/R10 | immutable run with actual fault paths and DB↔object joins; distinct first/retry attempts; no unsupported HA claim |
| **R13** | waiting | load-only L-06 evidence + L-P3-02, L-P3-05, L-P3-06, L-P3-07 | R04/R05 | 1000-anchor CTE series and actual SQL plan; record any separate core/drive fix decision; drive edits need R07 |
| **R14** | measured; mixed disposition; [evidence](remaining-evidence/R14.md) | load-only L-03 GC and L-P4-01 CAS pressure measurement | measurement independent; R02 media baseline before any later behavior edit | CAS integrity/memory GREEN; GC delete/RSS GREEN, scan throughput and 1000-row batch cap RED; separate media behavior fix required |
| **R15** | waiting | load-only L-07 CAS collision and remaining P5 product cells | R05 correct actors/limiter, R14 if media changes | actual product collision/serialization proof or unconfirmed; no status inferred from 429-only cells |
| **R16** | code prepared; PG+SQLite suites green; cold union pending; [evidence](remaining-evidence/R16.md); commit `2877998` | core mutation ≥95; old VLT-MUT P0.2/P1.1/P3 | R02 core PG+SQLite union; PG suite repaired | remove false parallel gate; cold union ≥95, regression-failing workflow |
| **R17** | code prepared; PG suite green; cold PG ≥95 pending; [evidence](remaining-evidence/R17.md); commit `3b7c3dd` | documents mutation ≥95; old VLT-MUT P0.2/P1.1/P4 | R02 documents PG baseline; suite repaired | remove false parallel gate; cold PG ≥95, regression-failing workflow |
| **R18** | complete; cold union 95.33%; [evidence](remaining-evidence/R18.md); commits `6a391cf`, `5a5384d` | media mutation ≥95; old VLT-MUT P5 | R02 media PG+SQLite union baseline (67.44%); R14 disposition recorded | 429 killed / 21 escaped / 450 IDs; both cold driver jobs and regression-failing 95% workflow gate proven |
| **R19** | ready | filament mutation ≥95; old VLT-MUT P6 | R02 filament SQLite baseline (59.26%) | cold SQLite ≥95 and regression-failing workflow |
| **R20** | waiting | final mutation, SLO, archived-risk and release/host readiness; old VLT-MUT P7 | R03–R19 as applicable | reconciled SLO table with GREEN/RED/unevaluated per tier, L-08 host scheduler contract, archived residuals and release/coverage/required-check status reverified; owner accepts any remaining deviations |

### R01 — historical implementation launch (proof now supplied by R09)

Use [the detailed L-04 brief](VLT-LOAD/brief/2026-09-23-l04-core-retry.md):

~~~text
$ task:dev plans/VLT-LOAD/brief/2026-09-23-l04-core-retry.md
~~~

### R02 / R07 / R16–R19 — mutation lane

The archived VLT-MUT plan supplies method and old escaped lists, not current execution authority. R02 first checks both the worktree and the archived findings, records current source/workflow digests, and reruns cold sequential baselines on PG/SQLite according to the documented skip matrix: drive and documents PG; core and media union; filament SQLite. Per-package work then writes behavioral tests, avoids unreasoned mutation ignores and proves a deliberate regression fails the workflow. The existing minima 43/54/66/63 are intermediate guards; core/documents 95 with `--parallel` are untrustworthy. Do not relabel a 100% parallel score as proof. R07 is the drive D11 unlock for R08 and drive part of R13. R16–R19 finish the other four packages as separate implementation slices. R20 revisits VLT-REMED/VLT-CANON/release-readiness claims. Workflow or package changes are separate scoped slices.

### R03–R06 — establish interpretable baselines

F-P2.8-1 records real tier-S throughput below SLO-40/43/49/52, while F-P2.8-3 records missing class-B per-scenario metrics; fixing tags/threshold materialization must not hide the throughput RED. F-P2.8-2 needs enough samples before a sparse TTFB/broadcast p95 can support a CV gate. R03 must attribute the tier-S throughput RED to a measured stand or package bottleneck; any behavior fix is a later single-package task with its own rerun. Until then R20 carries RED or an owner-accepted deviation. P3 needs batch INSERT/UPSERT bindings and SQL trace; it cannot assert no N+1 while the trace is empty. P5 needs distinct authenticated actors and actual status/lock/pool evidence; 429-only cells cannot prove L-07. R06 captures the failed sweeper command before any drive fix is chosen.

### R09–R12 — queue and chaos proof

P6 has 36 machine-RED product cells with sticky `failed_jobs=2`, zero dispatch UUIDs, leftover endpoints, a 100-row tick presented near a 10k volume seed, and a missing SUT scrape. R09 repairs these proof sources, then reruns the declared matrix. R10 addresses the independent core endpoint scan. P7 recorded 51 valid rows but used GET instead of the named product operations, sampled empty/wrong tables, hardcoded promote outcomes, repeated the same ENOSPC action for retry, and never had a green readiness baseline. R11 fixes the harness; R12 is a new authoritative product rerun. Neither changes P7's historical terminal receipt.

### R13–R15 — conditional scale findings

L-06 has one 13,067-row recursive CTE at 154.5 ms and no 1000-anchor series. L-03 is an unbounded-cursor/checkpoint hypothesis, not a measured tier-L failure; SLO-76…78 stay unchanged. L-P4-01 is repeated page-cache pressure with GREEN serial integrity; measure latency/concurrency before changing media. L-07 is unconfirmed because P5 saw only 429s. Planner-rejected HypoPG indexes and possible duplicate indexes are investigation outputs, not DDL authorization.

### R20 — what completion means

Publish a current matrix for each approved SLO and tier, retaining `not-checkable`/`unevaluated` where no valid run exists. The available tier-S stand does not prove M/L. Re-run package tests and the relevant load gates after product changes; reconcile trace/metric coverage with the current code. Confirm the documented host responsibility to schedule relay, retry, sweep and GC ([commands reference](../docs/reference/commands.md)); absence of package-owned cron is L-08, not a reason to claim host jobs ran. Check current CI coverage and required checks afresh: old VLT-REMED/VLT-MUT release-readiness and GitHub plan statements are dated historical evidence. Final close/archive of VLT-LOAD and disposition of RED or unevaluated results remain an explicit owner decision.

## 4. Work cards

Each card is a single bounded task input. Completion writes `plans/remaining-evidence/RNN.md` with source revision, source/run IDs, exact commands and exit codes, raw-artifact links, changed paths/commit, observed verdict, limitations and next unlocked ID. It also updates the State cell above. A missing or failing check is recorded as such. The carrier reports work; it does not replace immutable load artifacts.

### R02 — mutation proof inventory

Read the archived VLT-MUT P0.1/P0.2 findings, current five workflow files, five package lockfiles and P4 L-01 mutation JSON. Record the exact current source revisions and run cold, sequential DB-correct baseline measurements: drive/documents on PG, core/media as a union of PG and SQLite mutation IDs, filament on SQLite. Count tested/killed/escaped/timeouts/skips and preserve raw logs. If the full baseline is not runnable, state which package is unevaluated; no 100% from `--parallel` is accepted. Reconcile the P4 D11 claim explicitly; do not edit old journal/state.

### R03 — class A/B and tier-S throughput diagnosis

Read P2.7/P2.8, `slo-gates.md` and current `load/gates/thresholds.js`. Make declared per-scenario class-B metrics materialize with matching tags; preserve S0 advisory and lab policy. Give sparse TTFB/broadcast trends enough samples or retain them as unevaluated. Prove the declared window actually runs without a premature abort from an unrelated red threshold. Reproduce and attribute the tier-S SLO-40/43/49/52 throughput RED; no ceiling changes or unmeasured “calibration.” A package/stand behavior fix, if evidence points to one, is a new package-scoped row before R20.

### R04 — P3 DB proof

Read P3 report, L-P3-01/03 and the rejected EXPLAIN artifacts. Capture representative batch INSERT/UPSERT bindings, actual ANALYZE plans and a nonempty flushed query trace with command IDs. Recompute top-20 coverage and N+1 multiplicity from raw trace. The output may still report a bad query or an inconclusive plan; it cannot report “no N+1” from zero rows. DDL changes require a separate measured decision.

### R05 — P5 concurrency proof

Read P5 report and L-P5-01/02/04. Give each virtual user a distinct authenticated actor while keeping the intended same-resource contention. Persist actual HTTP status/Retry-After for collab issuance and setup. Sample PG locks and PgBouncer pools during the held window; empty samples are explicit empty arrays, not a success inference. Rerun the affected product cells; 429-only cells cannot establish lock collision or a 409 budget. Maintain source fingerprint and the existing SLO/error budgets.

### R06 — sweeper failure class

Read P5 dirty-20k/1m artifacts and L-P5-03. Make the failing command expose stderr, exit status, PHP peak, candidate/processed/remaining rows and `vaulter_sweep_batch_rows` where emitted. Reproduce the tier-S dirty-20k case on disposable data and classify the failure from its output. This task changes only the load capture path. It cannot claim a batching fix or SLO-70 GREEN.

### R07 — drive mutation gate

Use R02's drive baseline and the old escaped-mutation list as test targets. Add behavioral tests for surviving mutants in `packages/drive`, run on PG without `--parallel` and a cold cache, reach measured ≥95, retain the real skipped/timeout denominator and show a deliberate regression fails the workflow. Keep the required minimum at or above its current value; raise to 95 when supported. A smaller score does not unlock R08.

### R08 — drive sweeper behavior

Read R06 failure cause, R07 gate, `SweepUploadsCommand` and its concurrency tests. Implement the smallest bounded behavior change justified by the cause, including stable progress under >1000 due sessions, retries and overlapping ticks. Preserve terminal state semantics and no duplicate destructive action. Run focused and package checks, then a new authoritative tier-S SLO-70 run with throughput, tick p95, batch cap, RSS and remaining count. If no valid live run is available, report unremeasured RED.

### R09 — P6 queue evidence

Read P6 report, L-P6-01/02/03/04 and R01 outcome. Identify the two sticky failed jobs by payload/class and count per-cell deltas; join enqueue/processing UUIDs across dispatcher and worker. Reset leftover endpoints, stamp sink rows with the correct run ID, separate duplicate-control windows, drain the actual 10k seed through repeated ticks and keep the SUT scrape live. Rerun the declared tier-S matrix and recompute SLO-58/61, fan-out and queue-time. A clean fixture cannot erase observed product RED without new evidence.

### R10 — core endpoint fan-out

Read R09's clean fan-out fixture, P3 L-P3-04 and P6 L-05. Change only `packages/core` subscription enumeration with event filtering, dispatch ordering, duplicate protection and bounded memory preserved. Add cardinality and concurrent relay tests. Remeasure an authoritative tier-S fan-out/drain profile; 1-row 200/1000 endpoint diagnostics cannot prove SLO-59/60.

### R11 — P7 harness fidelity

Read P7 finding/report and all five L-P7 rows. Drive the named authenticated tus/S3/CAS/outbox/sweeper operations after injector ACK. Seed run-prefixed package rows and join DB↔object state in both directions. Measure post-fence/promote mutations rather than writing accepted/committed literals. Exercise the real short/failed ENOSPC write and a distinct retry after remount. Require a green baseline `/ready` and live `/metrics` before attributing recovery. Focused harness tests cover each failure mode.

### R12 — P7 authoritative rerun

After R11 and relevant product changes, run the full declared tier-S matrix with immutable run/source/stand identity and verifier. Check cell-to-operation equality, nonempty entity joins, cleanup of partial/orphan objects, distinct first/retry attempts, measured detection/restoration and readiness baseline. Publish per-cell product verdicts, preserving RED/unevaluated rows. No host/AWS HA guarantee follows from a local proxy control.

### R13 — tree/DB scale evidence

Read P3/P5 L-06 and L-P3-02/05/06/07. Capture a true 1000-anchor grant profile, recursive CTE count, actual SQL and tree operation times on a tier-matched dataset. Recheck planner-rejected HypoPG proposals and suspected duplicate indexes against this cardinality and write-path costs. Output a separate core or drive fix decision only if evidence supports it; a drive edit needs R07. No DDL is included here.

### R14 — GC and CAS pressure measurement

Read SLO-76…78, L-03 and L-P4-01. Capture bounded/checkpoint metrics, RSS, row/object throughput and remaining backlog for a tier-S GC run; keep M/L unclaimed without their profiles. Repeat 5-GiB CAS stage under controlled serial/concurrent pressure, recording duration, major faults and cgroup events with integrity. Distinguish page-cache reclaim from PHP heap growth. Only a measured adverse outcome can launch a separate `packages/media` behavior change, which needs its honest mutation gate.

### R15 — CAS collision control

Read P5 L-07 and R05's actor/limiter correction. Construct distinct keys that actually collide under the product advisory-key function, not merely a primitive lock ID; capture lock identity, HTTP outcome and unrelated-key delay. Repeat with independent actors. Close L-07 as confirmed, disproved or still unconfirmed from product evidence. Do not turn 429-only responses into a green collision verdict.

### R16–R19 — remaining package mutation gates

Each ID owns one package plus its workflow: R16 core (PG+SQLite union), R17 documents (PG), R18 media (PG+SQLite union), R19 filament (SQLite). Use R02's current cold baseline; write behavior tests for real escaped mutants, justify any cosmetic ignore, keep driver-specific skip counts, and reach a cold ≥95 score without `--parallel`. Verify each CI job can fail on a deliberate regression. Record separate commits and carriers; no aggregate 100% claim from a broken subprocess.

### R20 — final reconciliation and owner handoff

Check each R row's carrier and source digest, then publish a current SLO-by-tier matrix with GREEN/RED/unevaluated, report all remaining deviations and verify L-08 host scheduling on the actual deployment. Recheck current CI coverage, mutation jobs, required-check protection, archived plan residuals and Packagist split prerequisites. Preserve the v0.2.0 history and do not publish or change billing. Present concrete remaining owner decisions, including any RED or untestable M/L target, before final VLT-LOAD close/archive.

## 5. Archived residuals requiring an explicit disposition

These are historical open risks from archived plans. They do not silently reopen those plans or override the R01–R20 work order.

| Source | Historical remainder | Current route |
|:--|:--|:--|
| VLT-REMED handoff | suite coverage 78.6% below 80% and release-readiness RED in July | R20 remeasure current CI at its approved threshold; add focused tests or retain RED with owner disposition |
| VLT-CANON handoff / VLT-MUT Q1–Q2 | required checks unavailable under the recorded org/private-repo setup; core mutation job only push/dispatch | R20 verify current GitHub settings and PR triggers; owner handles any billing or policy decision; do not call advisory jobs enforcing |
| VLT-REL handoff D9 | Packagist split repositories, secret and registration were deferred; tag v0.2.0 exists but split run failed | R20 record current status; provisioning, publication and rerun require explicit owner authorization |
| VLT-CANON ADR-0018 | mixed `format` value-space without backfill | preserve accepted limitation; a data migration needs its own product decision and evidence |
| VLT-CANON handoff | ru contracts reference drift, documented nonreactive `registerEngineComponent`, azgard README/workflow mismatch | bounded docs or product follow-ups after higher-severity proof; do not claim they block R01 |
| VLT-REL handoff D12 | historical release docs carry superseded 0.1.0 examples | optional bookkeeping in archived material; do not fabricate a v0.1.0 tag |
| ROADMAP.md | engine-aware Filament UI and other deferred features | unscheduled; validate present code/ADR seam before any future owner launch |

Final R20 reports which rows were fixed, remain accepted limitations, require external owner action, or are stale after remeasurement. It does not publish, pay for a service or rewrite release history.

## 6. Launch syntax for later IDs

For a single row after its `Needs` column is satisfied, use the exact ID and this file as a bounded task input:

~~~text
$ task:dev plans/REMAINING-WORK.md RNN — выполнить только RNN, сохранить его Needs и Exit evidence
~~~

Replace both `RNN` tokens with the chosen ID. Each task must report the source run and changed package or harness. If new evidence disproves a candidate fix, update this map's row before starting a different behavior change.

## 7. Owner execution batches and model routing

**Owner routing, 2026-09-24:** use GPT-5.6 Sol, GPT-6 Sol, GPT-6 Astra for audits, Grok 4.6 and Grok 4.7. These explicit assignments override the installed Task adapter's default model routing for these launches only. They are workload assignments, not benchmark claims. No batch below has been executed by adding this schedule.

### Execution contract

- Start with `pwd` and inspect existing dirty/untracked paths. R01, R09, R10 and R02 are already evidenced; do not restart them. B04 / R03→R04 is the next implementation launch.
- B IDs are scheduling wrappers, **not** Task phase IDs or multi-ID `task:dev` arguments. The launcher reads the batch row, then applies `$ task:dev plans/REMAINING-WORK.md RNN` separately to each listed R, in order. Preserve the one-package-or-harness slice rule, per-R evidence carriers, checks and scoped commits; never absorb unrelated work into a commit. A batch shares context, not a combined completion verdict.
- Launch the numbered commands **one at a time**, checking the relevant `Needs` and prior audit findings before each dependent slice. A CLI exit code or completed conversation is not a gate. A valid RED product measurement can complete an evidence task; it cannot satisfy a required GREEN gate such as R07 ≥95 or R11 baseline readiness. If a prerequisite is absent, record the exact blocker and stop that dependent slice.
- Use the shared stand exclusively: no simultaneous load, chaos, mutation, database-reset or cache-cold runs, including across otherwise independent packages. No parallel editing agents in this worktree. Batches are sequential; do not turn the command list into an unconditional shell loop.
- In B08, each measurement has its own run ID, environment/source fingerprint and cleanup. R13/R14 do not authorize package behavior or DDL changes. New measured fixes need separate scoped rows and their gates; remeasure any later evidence invalidated by those fixes.
- B09–B12 keep mutation work in four separate package contexts. Recheck the R07 drive gate after R08 changes; recheck any other mutation proof whose source changed after its recorded run. Do this before B14 accepts the final source set. Do not replace an old result or claim that a pre-change score applies to new code.
- Astra audit batches inspect the actual scoped diff and raw evidence independently. They may write only `plans/remaining-evidence/Bxx-audit.md`; no implementation, status rewrites or automatic fixes. Record audited source digests, checks actually performed, severity with concrete impact, unresolved findings and disposition. The responsible implementation model resolves findings in the original R scope; obtain an Astra re-review before dependent work if a finding invalidates its prerequisite. Audits may inspect evidence without rerunning load; any requested rerun uses the same exclusive stand.
- R09's SLO-61 RED, concurrent duplicate delivery, recovery RED and unrecoverable historical failed-job identities remain explicit residuals. R10 does not silently acquire a separate duplicate-delivery fix. B16/B17 must carry these forward unless a separately evidenced disposition supersedes them.
- B16 can prepare a complete R20 owner handoff while deployment access or owner decisions remain outstanding; it must retain that incomplete disposition. B17 does not authorize publication, billing, final VLT-LOAD close/archive or acceptance of RED/M/L unknowns.

### Batch queue

| Batch | Ordered scope | Model | Admission / dependency | Intended result |
|:--|:--|:--|:--|:--|
| **B01** | R10 | `gpt-6-sol` | R09 accepted fixture | Core endpoint enumeration, bounded fan-out and tier-S proof. |
| **B02** | audit R09/R10 | `gpt-6-astra` | B01 | Independent core concurrency/evidence audit; preserve SLO-61 RED and concurrent duplicate residual. |
| **B03** | R02 | `grok-4.6` | none | Inventory plus cold sequential baselines for all five packages; no product changes. |
| **B04** | R03 → R04 | `gpt-6-sol` | R03 inputs; R04 only after R03 reusable summary | One load/DB context: class A/B evidence, throughput diagnosis, then SQL/trace proof. |
| **B05** | R05 → R06 | `gpt-6-sol` | R03; existing P5 artifacts | One P5 context: actors/lock/pool evidence, then sweeper failure capture. |
| **B06** | R07 → R08 | `gpt-6-sol` | R02 drive baseline; R06 cause; R08 only after R07 ≥95 | One drive context: honest mutation unlock, then measured sweeper behavior and rerun. |
| **B07** | audit R02–R08 | `gpt-6-astra` | B03–B06 | Audit baseline validity, load controls, drive concurrency and SLO-70 evidence. |
| **B08** | R13 → R14 → R15 | `grok-4.7` | R04/R05; B07 findings resolved where relevant | Sequential load-only measurement campaign: tree/DB, GC/CAS pressure, actual collision control. |
| **B09** | R16 | `gpt-5.6-sol` | R02 core baseline; R10 and relevant B02 findings resolved | Core final cold PG+SQLite union mutation gate. |
| **B10** | R17 | `gpt-5.6-sol` | R02 documents baseline | Documents cold PG mutation gate. |
| **B11** | R18 | `gpt-5.6-sol` | R02 media baseline; R14 disposition | Media cold PG+SQLite union mutation gate. |
| **B12** | R19 | `gpt-5.6-sol` | R02 filament baseline | Filament cold SQLite mutation gate. |
| **B13** | R11 | `gpt-6-sol` | R05/R09 fixtures; R08 final sweeper path; R10 settled | P7 harness fidelity, operation/state joins, ENOSPC retry and baseline readiness. |
| **B14** | audit R11 + mutation gates | `gpt-6-astra` | B08–B13 | Pre-chaos audit: injector semantics, cleanup/retry, R07 and R16–R19 gates on current sources. |
| **B15** | R12 | `gpt-6-sol` | R11; R01/R08/R10; B14 has no unresolved findings invalidating the run | Full authoritative tier-S chaos matrix on a frozen source/stand snapshot. |
| **B16** | R20 | `grok-4.6` | R03–R19 evidence/dispositions as applicable | Reconcile source digests, SLO tiers, CI and host/release residuals; retain external unknowns. |
| **B17** | audit R12/R20 + final map | `gpt-6-astra` | B15/B16 | Final independent evidence audit and owner handoff; no release or archive action. |

### Full CLI launch commands

Run from any directory; each command explicitly selects this workspace. Prompts use single quotes so the shell passes literal `$ task:dev`. CLI option syntax was checked against installed help: Codex uses `-m`, Cursor uses **`cursor agent --model`** (`cursor -m` is the IDE merge option). Local Cursor configuration contains `grok-4.6` and `grok-4.7`; online availability could not be verified because `cursor agent --list-models` failed with `getaddrinfo EAI_AGAIN api2.cursor.sh`. GPT-6 Sol is the owner's explicit requested ID; the local Codex cache lists GPT-5.6 Sol/Astra but does not establish GPT-6 Sol account availability. If the backend rejects an ID, resolve availability before launch; do not silently substitute a different model.

~~~bash
# B01 — R10
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B01 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B02 — audit R09/R10
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' 'Проведи аудит B02 по разделу 7 plans/REMAINING-WORK.md. Сохрани только отчет аудита; код и статусы R не изменяй.'

# B03 — R02
cursor agent --workspace /home/vostrikov/projects/packages/vaulter --model grok-4.6 'Выполни B03 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B04 — R03 → R04
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B04 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B05 — R05 → R06
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B05 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B06 — R07 → R08
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B06 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B07 — audit R02–R08
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' 'Проведи аудит B07 по разделу 7 plans/REMAINING-WORK.md. Сохрани только отчет аудита; код и статусы R не изменяй.'

# B08 — R13 → R14 → R15
cursor agent --workspace /home/vostrikov/projects/packages/vaulter --model grok-4.7 'Выполни B08 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B09 — R16
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-5.6-sol -c 'model_reasoning_effort="high"' 'Выполни B09 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B10 — R17
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-5.6-sol -c 'model_reasoning_effort="high"' 'Выполни B10 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B11 — R18
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-5.6-sol -c 'model_reasoning_effort="high"' 'Выполни B11 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B12 — R19
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-5.6-sol -c 'model_reasoning_effort="high"' 'Выполни B12 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B13 — R11
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B13 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B14 — audit R11 + mutation gates
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' 'Проведи аудит B14 по разделу 7 plans/REMAINING-WORK.md. Сохрани только отчет аудита; код и статусы R не изменяй.'

# B15 — R12
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-sol -c 'model_reasoning_effort="high"' 'Выполни B15 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B16 — R20
cursor agent --workspace /home/vostrikov/projects/packages/vaulter --model grok-4.6 'Выполни B16 по разделу 7 plans/REMAINING-WORK.md. Применяй $ task:dev отдельно к каждому R в указанном порядке.'

# B17 — audit R12/R20 + final map
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' 'Проведи аудит B17 по разделу 7 plans/REMAINING-WORK.md. Сохрани только отчет аудита; код и статусы R не изменяй.'
~~~
