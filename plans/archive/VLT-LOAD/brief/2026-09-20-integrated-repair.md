# Owner brief — integrated VLT-LOAD repair

**Date:** 2026-09-20
**Actor:** owner/Dmitry Vostrikov
**Mode:** design-only; no product implementation, commit, push or publication.

## Mandate

Perform one integrated repair of remaining P3–P7 and P8.8 rather than a separate future P8.8 design
session. Reconcile stable specs, decisions, lifecycle evidence, routing, handoff and generated views;
then stop with exactly one semantic successor: a full `task:plan-audit VLT-LOAD`.

## Non-negotiable outcomes

- Preserve D40 as history and record the superseding owner decision.
- Make P8.8 atomic and executable, splitting mixed implementation/proof scope where necessary.
- Do not fabricate GREEN for P8.5/P8.7; distinguish fixture evidence from the missing live gate.
- Make P4 download checksum claims technically compatible with bounded clients and 5 GiB payloads.
- Require terminal P5/P6 evidence before P7 and distinguish valid product RED from invalid evidence.
- Keep `plan-exec` forbidden until one accepted full audit; do not start another design/audit cycle
  without a concrete new defect.
