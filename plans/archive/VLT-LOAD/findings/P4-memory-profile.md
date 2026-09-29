# P4 memory findings — bounded debt after tier-S matrix

**Date:** 2026-09-22  
**Source run:** `8f383737-406e-42b9-addd-3ecf4ef6fc2b`  
**Profile:** [`memory-profile.md`](../memory-profile.md)  
**Disposition:** P4 memory envelope GREEN; L-01 closed; one bounded non-blocking debt

## L-01 final outcome

L-01 is closed GREEN. `s3_complete_postfix` completed 500 MiB and 5 GiB objects three times each
with exact byte/checksum evidence. PHP allocator peak was 2 MiB in every attempt against the fixed
160 MiB ceiling, and process `VmHWM` stayed near 9.3 MiB. The package fix and D11 mutation carrier
are recorded in [`L-01-streaming-fix-closure.md`](L-01-streaming-fix-closure.md) and
[`artifacts/P4-l01-mutation.json`](../artifacts/P4-l01-mutation.json).

## L-P4-01 — CAS 5 GiB stage reaches cgroup memory.max through file cache

| Attribute | Value |
|:--|:--|
| Severity | Medium |
| Confidence | High for the repeated pressure signal; medium for user impact because latency/concurrency were not measured here |
| Path / size | `cas_stage_probe/5g` |
| Product verdict | GREEN ×3; no OOM, restart, partial write or checksum mismatch |
| Observed | PHP peak 32 MiB `<64 MiB`; process anonymous delta 0; cgroup `file` median max 1997.3 MiB; post-baseline `memory.events.max` delta 7119 / 6476 / 6344 |
| Interpretation | `Sha256ContentStore::stage()` streams in 1 MiB chunks to a local staged file. The evidence shows filesystem/page-cache reclaim at the 2 GiB container envelope, not whole-file PHP buffering. |
| Affected obligation | Internal CAS-stage envelope supporting P4; possible latency/concurrency pressure, no failed SLO in this serial run |
| Owner | `packages/media` for product semantics; `load/` for a controlled pressure/latency reproduction |
| Candidate dev slice | Measure stage duration, major faults and `events.max` under isolated serial/concurrent 5 GiB staging; decide from that evidence whether deployment headroom, cache handling or a media-package change is warranted. |
| D11 gate | Any `packages/media` behavior change requires its VLT-MUT package score ≥95 first; measurement-only `load/` work does not. |

Evidence:

- [repetition 1](../artifacts/P4/samples/84590d30-41d3-4088-b911-da547ff852d1/samples.jsonl):
  `events.max` 5273 → 12392, zero OOM/kill.
- [repetition 2](../artifacts/P4/samples/18fca2d5-3c76-480f-95e9-a76171192ae1/samples.jsonl):
  `events.max` 3143 → 9619, zero OOM/kill.
- [repetition 3](../artifacts/P4/samples/8caee723-11eb-420b-9b83-d7b567a48971/samples.jsonl):
  `events.max` 5537 → 11881, zero OOM/kill.
- Source anchor: `packages/media/src/Cas/Sha256ContentStore.php::stage()` reads and writes in bounded
  1 MiB chunks; this supports the page-cache classification without claiming a remediation.

## Other paths

No additional P4 memory debt is opened. Tus, S3 putPart, post-fix complete and download have flat
allocator and process curves through 5 GiB. Large absolute cgroup peaks on those cells are dominated
by `file`, often already present at the `before` sample after fixture preparation; their
post-baseline `events.max` deltas are zero and all product/integrity checks are GREEN.
