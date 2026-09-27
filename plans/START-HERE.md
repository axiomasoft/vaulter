# START HERE — durable handoff

**Updated:** 2026-09-23
**Purpose:** cold start without chat history.

Read [ACTIVE.md](ACTIVE.md), [REMAINING-WORK.md](REMAINING-WORK.md) and [RUNBOOK.md](RUNBOOK.md). For VLT-LOAD, cross-check stable plan and append-only journal against generated status/state and the P8 receipt; do not edit generated views. Execution is terminal with deviations, while owner close/archive is postponed.

## Current launch

R01 is the independent core retry finding L-04, not a VLT-LOAD Pn.m:

~~~text
$ task:dev plans/VLT-LOAD/brief/2026-09-23-l04-core-retry.md
~~~

The brief preserves SLO-64 ceilings and requires a new authoritative tier-S run before a product GREEN claim. A clean package test alone does not close the RED verdict.

## Route after R01

Follow R02–R20 and their `Needs`/`Exit evidence` columns in [REMAINING-WORK.md](REMAINING-WORK.md). The next independent lane is mutation proof recovery: the old VLT-MUT plan is archived while incomplete, current core/documents CI still use false `--parallel` mutation measurement, and P4 L-01 reused the same flag for D11. Do not launch the archived P0.2 as-is or lower the ≥95 target. P5/P6/P7 evidence repairs precede renewed product conclusions; M/L diagnostic probes stay non-authoritative.

Live AUDIT-VLT and VLT-HSEAM migration previews contain stale P0.1 commands. Their archive/supersession dispositions are in [RUNBOOK.md](RUNBOOK.md). The root ROADMAP is an optional feature parking lot outside this completion route.
