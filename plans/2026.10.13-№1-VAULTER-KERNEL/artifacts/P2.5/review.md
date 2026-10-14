# P2.5 review — round 1

- Reviewer: grok/grok-4.7, effort high (owner request «ревью на grok 4.7»).
- Launch: `plan.py bridge orch launch --model grok-4.7 --effort high reviewer grok` (SKM_ORCH_ROOT=swissknifeman/packages/orch; read-only `ro-run.py`, write tools disallowed). Brief: `review-brief.md`; stream: `review-grok.jsonl`.
- Actual session `01a13887-89ee-7d91-8816-572dd11b9360`: summary.json current_model_id=grok-4.7, reasoning_effort=high; signals.json contextWindowTokens=256000. 46 turns, 24 min.
- Verdict: `FINDINGS` (5). The executor reproduced all five with the reviewer's commands before correcting anything.

| # | Severity | Finding | Executor verdict | Correction |
|:--|:--|:--|:--|:--|
| 1 | medium | scan-baseline: needs-explicit-require pair counts used the first type of the file (`?`/core→core buckets); symbol-precise owners give documents→core 396, webhooks→core 177, filament→core 82, collaboration→drive 41, collaboration→core 31, azgard→core 10 | confirmed | numbers replaced; the reverse-edge pairs were recomputed the same way (core→drive 324, core→webhooks 62, documents→collaboration 27, media→drive 24, core→media 8, core→documents 4, drive→documents 4, drive→webhooks 4) |
| 2 | medium | OQ5 closed Label/Icon/HasLabel/HasIcon/EnumCaseAttributeResolver as settled; architecture#kernel keeps them in core only if the kernel consumes them, D27 is silent | confirmed: consumers are enums of media, drive and collaboration, and media cannot depend on drive (D6) → architecture fork | open D35; OQ11 in open-questions |
| 3 | low | morph identities: 20 getMorphClass, 2 morphTo, 1 morphMany, 1 requiresMorphMap | confirmed | wording fixed |
| 4 | low | OQ5 «Webhooks-namespace (8)» is 7; SchemaKeyTypesCheck is a P3 contribution, not a D29 rename | confirmed | 7 + SchemaKeyTypesCheck as a separate item |
| 5 | low | «move 407 в P4»: phase P4 = 407 (move 399, replace 4, split 4) | confirmed | wording fixed |
