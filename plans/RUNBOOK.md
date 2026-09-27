# RUNBOOK — route from terminal plans to verified completion

**Revised:** 2026-09-23
**Canonical work queue:** [REMAINING-WORK.md](REMAINING-WORK.md), R01–R20. This file records how to use that queue; it does not create new VLT-LOAD phase items.

## Lifecycle and authority

- VLT-LOAD is 🟠 Done with deviations and P8 11/11 GREEN. Its journal, generated status/state and terminal P8 receipt are the lifecycle record. Final close/archive is deferred by the owner. Use [handoff](VLT-LOAD/handoff.md) for terminal state, not an old launch block in roadmap or bundle.
- AUDIT-VLT is archived as a completed audit. VLT-HSEAM was superseded by VLT-CANON P3.3 / ADR-0018. Their live Layout-v2 migration previews show 0/N and stale P0.1; do not execute them.
- VLT-REMED, VLT-CANON and VLT-REL are archived histories. VLT-MUT is physically archived but its own plan remains In progress; recovery runs through R02/R07/R16–R20, not the archive as an admitted Task plan.
- Product verdicts and evidence limits come from the corresponding VLT-LOAD reports/findings and approved [SLO](VLT-LOAD/slo.md)/[gate](VLT-LOAD/slo-gates.md) contracts. Terminal GREEN proves declared execution, not every product target.

## Work order

1. **R01 is next:** core L-04; [brief](VLT-LOAD/brief/2026-09-23-l04-core-retry.md). It is independent of drive D11 per P6 finding.
2. **R02 mutation proof and R03–R06 measurement repairs:** current mutation methods and baseline validity, class-A/B gate, DB trace, P5 actors/status/pool and sweeper failure class. These establish the inputs to later behavior fixes.
3. **R07/R08 drive and R09/R10 core/P6:** only the honest drive ≥95 carrier admits a drive sweeper change. P6's sticky failed_jobs, missing dispatch UUIDs, dirty endpoint fixture and incomplete 10k drain must be repaired before queue product GREEN.
4. **R11/R12 P7:** replace labelled GETs, empty/wrong DB sampler, hardcoded promote and duplicate retry action with actual operations, then run a new tier-S matrix. Historical P7 terminal state is not reclassified.
5. **R13–R19 conditional scale findings and package mutation:** measure L-06/L-03/L-07 before behavior edits; finish each remaining package's cold mutation gate separately. Keep L-P4-01 as measured page-cache pressure until an impact run warrants a media fix.
6. **R20 final reconciliation:** current SLO/tier matrix, host scheduling obligation L-08, CI/release claims and owner disposition of any RED/unevaluated items. Optional ROADMAP features are outside this queue.

## Proof and safety gates

- Never lower approved SLO/proof ceilings or use a stand/S0 fingerprint as a tier-S result. The 50k/500k retry probes, 200/1000-endpoint one-row probes and P5 million-row probe are labelled non-SLO diagnostics.
- The P4 L-01 mutation carrier used pest-plugin-mutate v4.0.1 with `--parallel`, which VLT-MUT had reproduced as a false 100% score. Preserve the streaming memory result; treat D11 ≥95 as unproved until a cold correct run. Do not reduce existing mutation minima to make CI pass.
- Product fixes and measurement-tool repairs are separate package/harness slices with their own tests and evidence. A failed, skipped, stale or unavailable verification is not GREEN. Generated status/state and append-only journal are not edited for portfolio routing.
- Host scheduling examples already exist in [commands reference](../docs/reference/commands.md); a package does not automatically register cron. Reverify a host deployment before claiming an operational drain.
- Push, publication, deletion and overwriting unrelated work require explicit owner authorization.

## Current command

~~~text
$ task:dev plans/VLT-LOAD/brief/2026-09-23-l04-core-retry.md
~~~

For later IDs, use the bounded command template in [REMAINING-WORK.md](REMAINING-WORK.md) after its dependencies are satisfied.
