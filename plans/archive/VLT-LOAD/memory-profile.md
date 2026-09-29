# P4 memory profile — tier-S streaming envelope

**Date:** 2026-09-22  
**Run:** `8f383737-406e-42b9-addd-3ecf4ef6fc2b`  
**Tier / matrix:** S · 15 cells · 3 successful repetitions · 45/45 valid  
**Stable digest:** `b204a1d1749330a3156fe1065eab2d3c4fc3c1aaff2738eef77658b4a5f70a3e`  
**Verdict:** GREEN — bounded at the tested 5 MiB → 500 MiB → 5 GiB envelope

## Run validity

The authoritative live manifest is [`artifacts/P4/run-manifest.json`](artifacts/P4/run-manifest.json).
It declares `mode=live`, tier S, 45 attempts, 15 cells and three repetitions per cell. Every attempt
is `valid` and product GREEN; every cell aggregate is GREEN. The required `verify --run-id` command
returned the stored digest twice after publication. All 45 attempts have matching client/content
byte accounting, matching server/probe SHA-256 and zero cgroup OOM/OOM-kill events.

The fixed allocator ceilings are the values published before interpretation in each
`case-manifest.json`: tus 64 MiB, S3 putPart/complete 160 MiB, CAS stage 64 MiB and download 16 MiB.
No ceiling was changed after the run.

## Measurement semantics

- `PHP peak` is `memory_get_peak_usage(true)`: PHP allocator capacity, not RSS.
- `VmHWM` is the sampled process lifetime resident high-water mark from `/proc/<pid>/status`.
- `Pss_Anon` and `Pss_File` are process-level `smaps_rollup` values. Deltas below are the maximum
  sampled value minus the first `before` sample for the same attempt.
- cgroup values cover the whole fresh benchmark container. `memory.peak` is a container-lifetime
  high-water mark and may include fixture preparation before the request baseline; `anon`, `file`
  and `sock` keep that total attributable. `events.max` below is the post-baseline counter delta.
- `cas_stage_probe` is a PHP CLI process. The other four paths are isolated single-worker FPM cases.

## Cell verdicts and process footprint

All numbers are MiB and shown as `min/median/max` across the three repetitions. Each cell links all
three immutable attempt manifests; their sibling `samples.jsonl` files carry the raw series.

| Cell | PHP peak MiB min/med/max | Ceiling MiB | VmHWM MiB min/med/max | ΔPss anon MiB | ΔPss file MiB | Result | Evidence |
|:--|--:|--:|--:|--:|--:|:--:|:--|
| `tus_final_patch/5m` | 2.0/2.0/2.0 | 64 | 9.3/9.4/9.4 | 0.0/0.1/0.1 | 0.0/0.1/0.1 | GREEN | [r1](artifacts/P4/samples/819f56b7-4e60-435f-ae13-cc89a92735a1/run-manifest.json) [r2](artifacts/P4/samples/4a058472-81ac-464b-98eb-e1e9e1872f0d/run-manifest.json) [r3](artifacts/P4/samples/e77ce3b8-d541-4571-9548-480319996b0f/run-manifest.json) |
| `s3_putpart/5m` | 2.0/2.0/2.0 | 160 | 9.3/9.4/9.4 | 0.1/0.1/0.1 | 0.1/0.1/0.1 | GREEN | [r1](artifacts/P4/samples/bb701b75-3051-4c38-9199-5c8a5d78eb44/run-manifest.json) [r2](artifacts/P4/samples/28953697-4aba-4f71-a922-c116ed6d5616/run-manifest.json) [r3](artifacts/P4/samples/e23d024a-7fd3-4ce1-b272-ec29276ce7e6/run-manifest.json) |
| `s3_complete_postfix/5m` | 2.0/2.0/2.0 | 160 | 9.3/9.4/9.4 | 0.0/0.1/0.1 | 0.0/0.1/0.1 | GREEN | [r1](artifacts/P4/samples/380532df-1fcf-4f30-ad73-ed7c0a14ab0e/run-manifest.json) [r2](artifacts/P4/samples/c20dbfd4-846d-4b3a-9b05-8fc679b60004/run-manifest.json) [r3](artifacts/P4/samples/7ef01cfb-712d-49a7-9e63-95df87ede720/run-manifest.json) |
| `cas_stage_probe/5m` | 32.0/32.0/32.0 | 64 | 1.1/1.1/1.2 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/66446814-4696-4684-acfa-650bce8e323c/run-manifest.json) [r2](artifacts/P4/samples/a91750a0-ce8a-49ce-94ff-1711480f5ddf/run-manifest.json) [r3](artifacts/P4/samples/60f47fed-50ad-4564-b50f-68027b092bd4/run-manifest.json) |
| `download/5m` | 2.0/2.0/2.0 | 16 | 9.4/9.4/9.4 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/98a9a580-b204-4cc3-8821-188b041c9a47/run-manifest.json) [r2](artifacts/P4/samples/b072588e-072b-4ac4-bb28-9f80686a4ea9/run-manifest.json) [r3](artifacts/P4/samples/500e341a-7793-4ba8-af0b-47dee406dd15/run-manifest.json) |
| `tus_final_patch/500m` | 2.0/2.0/2.0 | 64 | 9.3/9.4/9.4 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/21c8a6ff-8821-4bf7-8864-0290f0d99ced/run-manifest.json) [r2](artifacts/P4/samples/2f6bbad8-25cd-49d9-ae92-fc191314fa12/run-manifest.json) [r3](artifacts/P4/samples/e7aac78c-d63f-40da-857f-d253b2415dea/run-manifest.json) |
| `s3_putpart/500m` | 2.0/2.0/2.0 | 160 | 9.4/9.4/9.4 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/e51e2386-3cc9-4961-9bb0-94ea30ca1ec8/run-manifest.json) [r2](artifacts/P4/samples/25ebcea3-f464-44a1-a18f-4b1da3a4f8f8/run-manifest.json) [r3](artifacts/P4/samples/ae536365-84b9-4e12-8b49-4fb73a8fcfa8/run-manifest.json) |
| `s3_complete_postfix/500m` | 2.0/2.0/2.0 | 160 | 9.3/9.3/9.4 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/2d8c130d-8bc6-42e4-b76d-79122f6acec4/run-manifest.json) [r2](artifacts/P4/samples/09ee6110-52e9-47ff-b008-d805983eb7e7/run-manifest.json) [r3](artifacts/P4/samples/8c69f856-d5d2-4eb9-b265-a36e0127c485/run-manifest.json) |
| `cas_stage_probe/500m` | 32.0/32.0/32.0 | 64 | 1.2/1.2/1.2 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/f3f07693-318e-4f06-bfe9-c1d85db5cca2/run-manifest.json) [r2](artifacts/P4/samples/7e706e15-3d15-4506-b882-66517fa5b6ed/run-manifest.json) [r3](artifacts/P4/samples/a47dfa2f-d2f4-4693-9488-83bc76b128a8/run-manifest.json) |
| `download/500m` | 2.0/2.0/2.0 | 16 | 9.3/9.3/9.4 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/64fe2d41-f4cc-4736-83f6-13bb0a11b434/run-manifest.json) [r2](artifacts/P4/samples/3ad8ec90-94b5-4cb4-ab39-cf06d096beb1/run-manifest.json) [r3](artifacts/P4/samples/24930ad3-c97f-40c7-b51b-fbd77633d349/run-manifest.json) |
| `tus_final_patch/5g` | 2.0/2.0/2.0 | 64 | 9.3/9.3/9.3 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/122d1edc-2d3b-4e03-a767-bfe151ba693c/run-manifest.json) [r2](artifacts/P4/samples/9960f4f4-342b-4673-a739-bf4b7ef13106/run-manifest.json) [r3](artifacts/P4/samples/e2fbf5e4-eb79-4118-adbb-24bce16eeb51/run-manifest.json) |
| `s3_putpart/5g` | 2.0/2.0/2.0 | 160 | 9.3/9.3/9.3 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/ef3ac339-c2fe-40f8-a86e-01bb699655e1/run-manifest.json) [r2](artifacts/P4/samples/d7f6c1b1-c7e6-4320-968e-7ee9ca6e0fee/run-manifest.json) [r3](artifacts/P4/samples/545ee436-072d-4dc3-8e94-a866a67011c0/run-manifest.json) |
| `s3_complete_postfix/5g` | 2.0/2.0/2.0 | 160 | 9.3/9.3/9.3 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/6e4539ac-65e2-4dd8-a4f8-f2a10fcc6066/run-manifest.json) [r2](artifacts/P4/samples/7d9b442f-ce9f-4d09-b62e-b2f52dccd371/run-manifest.json) [r3](artifacts/P4/samples/e861e8bb-84c6-4442-9c2d-3867900ccb00/run-manifest.json) |
| `cas_stage_probe/5g` | 32.0/32.0/32.0 | 64 | 1.1/1.1/1.1 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/84590d30-41d3-4088-b911-da547ff852d1/run-manifest.json) [r2](artifacts/P4/samples/18fca2d5-3c76-480f-95e9-a76171192ae1/run-manifest.json) [r3](artifacts/P4/samples/8caee723-11eb-420b-9b83-d7b567a48971/run-manifest.json) |
| `download/5g` | 2.0/2.0/2.0 | 16 | 8.9/9.1/9.3 | 0.0/0.0/0.0 | 0.0/0.0/0.0 | GREEN | [r1](artifacts/P4/samples/d1a5b9be-2ec9-4ff4-b760-1705f1af841e/run-manifest.json) [r2](artifacts/P4/samples/675e7f18-dc40-44e6-96f5-fafd9b1a17d1/run-manifest.json) [r3](artifacts/P4/samples/b092b8d3-de48-440e-afce-475ed67be140/run-manifest.json) |

The allocator curve is flat by path across the tested sizes: 2 MiB for every FPM case and 32 MiB
for CAS CLI. FPM `VmHWM` stays about 9 MiB. No process-level anonymous or file-backed delta grows
with payload size.

## Container and page-cache footprint

These are container-level values. `anon`, `file` and `sock` are the median of each repetition's
maximum sampled absolute value; they are not relabelled as PHP RSS.

| Cell | cgroup peak MiB min/med/max | anon MiB median max | file MiB median max | sock MiB median max | Δ `events.max` min/med/max | OOM/kill |
|:--|--:|--:|--:|--:|--:|:--:|
| `tus_final_patch/5m` | 93.7/97.1/100.6 | 43.5 | 50.0 | 4.2 | 0/0/0 | 0 |
| `s3_putpart/5m` | 97.5/97.7/98.4 | 47.4 | 44.7 | 0.1 | 0/0/0 | 0 |
| `s3_complete_postfix/5m` | 97.9/98.3/98.6 | 42.5 | 44.3 | 0.0 | 0/0/0 | 0 |
| `cas_stage_probe/5m` | 57.6/57.7/57.8 | 18.2 | 2.5 | 0.0 | 0/0/0 | 0 |
| `download/5m` | 87.1/87.1/87.3 | 40.7 | 35.2 | 0.0 | 0/0/0 | 0 |
| `tus_final_patch/500m` | 91.4/515.0/599.2 | 21.7 | 459.9 | 0.0 | 0/0/0 | 0 |
| `s3_putpart/500m` | 500.4/511.2/515.1 | 18.1 | 460.5 | 0.0 | 0/0/0 | 0 |
| `s3_complete_postfix/500m` | 720.0/722.0/726.5 | 18.1 | 460.8 | 0.0 | 0/0/0 | 0 |
| `cas_stage_probe/500m` | 562.7/573.7/941.1 | 18.2 | 45.7 | 0.0 | 0/0/0 | 0 |
| `download/500m` | 1111.8/1177.2/1300.7 | 18.1 | 460.6 | 0.0 | 0/0/0 | 0 |
| `tus_final_patch/5g` | 1207.1/1678.8/2048.0 | 18.2 | 1219.7 | 0.0 | 0/0/0 | 0 |
| `s3_putpart/5g` | 1520.2/1543.8/1738.1 | 18.1 | 1457.9 | 0.0 | 0/0/0 | 0 |
| `s3_complete_postfix/5g` | 2048.0/2048.0/2048.0 | 19.3 | 1960.1 | 0.0 | 0/0/0 | 0 |
| `cas_stage_probe/5g` | 2048.0/2048.0/2048.0 | 18.2 | 1997.3 | 0.0 | 6344/6476/7119 | 0 |
| `download/5g` | 2072.3/2078.6/2079.5 | 18.3 | 1990.3 | 0.0 | 0/0/0 | 0 |

The size-correlated cgroup curve is overwhelmingly `file`, while container `anon` remains around
18–22 MiB for the large cases. This is filesystem/page-cache footprint from preparing, reading and
writing multi-GiB fixtures, not a PHP heap curve. The repeatable post-baseline `events.max` increase
on `cas_stage_probe/5g` is retained as L-P4-01; it completed all repetitions without OOM or checksum
failure, but demonstrates reclaim pressure at the 2 GiB cgroup envelope.

## SLO and L-01 disposition

| Path | Tier-S SLO plane | Memory result | Scope boundary |
|:--|:--|:--|:--|
| tus final PATCH | SLO-40 allocator/RSS component | GREEN: 2 MiB `<64 MiB` ×9 | throughput and chunk latency are outside this matrix |
| S3 putPart | SLO-43 allocator/RSS component | GREEN: 2 MiB `<160 MiB` ×9 | each request transfers one bounded part |
| S3 complete post-fix | SLO-46 allocator/RSS component | GREEN: 2 MiB `<160 MiB` ×9 | includes 5 GiB object integrity ×3 |
| CAS stage probe | internal supporting ceiling | GREEN: 32 MiB `<64 MiB` ×9 | CLI process; L-P4-01 tracks cgroup pressure |
| download | SLO-52 allocator/RSS component | GREEN: 2 MiB `<16 MiB` ×9 | k6 discards response body; server/probe checksum is separate |

L-01 is closed. The 500 MiB and 5 GiB `s3_complete_postfix` cells are GREEN in all six attempts,
with a flat 2 MiB PHP allocator peak, stable process footprint, exact byte accounting and matching
object hashes. The implementation uses bounded streamed assembly, and the D11 package mutation gate
tested 1661 mutations at 100.00% against a 95% minimum. The closure carrier is
[`findings/L-01-streaming-fix-closure.md`](findings/L-01-streaming-fix-closure.md).

## Bounded debt and limitations

The only new row is L-P4-01 in [`findings/P4-memory-profile.md`](findings/P4-memory-profile.md).
It records CAS-stage cgroup reclaim pressure, not a heap leak or a failed product verdict.

The evidence supports “bounded at the tested envelope”; three sizes do not prove asymptotic
complexity. It covers tier S with one isolated operation at a time and makes no claim for tiers M/L,
concurrency, warm/cold latency or AWS-native multipart completion. Host caches were not dropped.
Container lifetime peaks can include fixture preparation; request attribution uses before-to-after
deltas. Download integrity comes from the separate bounded server-object probe because the k6 client
uses `responseType: none`.

## Validation

```text
TIER=S ./load/scripts/p4-memory.sh verify --run-id 8f383737-406e-42b9-addd-3ecf4ef6fc2b
  verify-matrix: ok digest=b204a1d1749330a3156fe1065eab2d3c4fc3c1aaff2738eef77658b4a5f70a3e (twice)

profile consistency:
  45 valid attempts · 15 unique cells · repetitions {1,2,3} · 15 aggregate GREEN
  45/45 byte-accounting and server/probe checksum matches · 45/45 zero OOM/kill
  fixed ceilings match every case manifest · all 45 allocator peaks below their ceiling
  all 45 attempt evidence links resolve
```
