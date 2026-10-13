# P2.3 review — round 1

- Reviewer: grok/grok-4.7 (owner request «ревью на grok 4.7»; effort `high` by default, context not requested).
- Launch: `plan.py bridge orch launch --model grok-4.7 --effort high reviewer grok` (read-only), brief `review-brief.md`, stream `review-grok.jsonl`.
- Actual session: `01a13710-4ea4-7772-8d59-300a7ab0ef0e` (headless, `~/.grok/sessions/<cwd>/01a13710-…/`); model from its record: `grok-4.7` (summary.json `current_model_id`, updates.jsonl `modelId`). The session record carries no effort and no `contextWindowTokens` field, so effort `high` and context capacity are NOT proven from it (they come from the launch argv only).
- Duration 30 min, 85 turns. Verdict: `FINDINGS` (3). All three reproduced by the executor with the reviewer's commands or by its own run before any correction.

| # | Severity | Finding | Executor verdict | Correction |
|:--|:--|:--|:--|:--|
| 1 | medium | `route:vaulter.v1.documents.collaboration.session` owned by documents; its controller is collaboration's in the type-map | confirmed (`class_owner=collaboration` in http.json) | owners/http.json: prefix `route:vaulter.v1.documents.collaboration.` → collaboration; IdentitiesTest asserts owner and absence of `class_owner` |
| 2 | medium | `problem:permission_denied` owned by drive through the blanket `problem:` prefix; architecture#errors lists it as a core common code | confirmed (architecture#errors, owner=drive in protocol.json) | owners/protocol.json: exact `problem:permission_denied` → core; test asserts it |
| 3 | low | `Http::build` keeps the default boot's uri/middleware (`??=`): a flag run that changes them leaves `--check` green | confirmed by reading the loop; fixed by construction | `Http::merge()` extracted; a run whose uri/methods/middleware/action differs gives drift `flag-changes-route` (exit 1); two IdentitiesTest cases on synthetic runs |

After the correction: V1–V6 rerun, all exit 0 (repo suite green, fixtures regenerated for http/protocol only).
