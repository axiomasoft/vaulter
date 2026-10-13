# P2.1 — review verdict (round 1)

- Reviewer: grok/grok-4.7, effort high, session `01a136bf-dd19-7a31-8f04-a505dbf9ccf8` (proved from `~/.grok/sessions/…/summary.json` reasoning_effort=high, `signals.json` contextWindowTokens=256000, `updates.jsonl` model grok-4.7). Launch via `plan.py bridge orch launch reviewer grok`, read-only (`ro-run.py`, write tools disallowed). Stream: `review-grok-stream.jsonl`; full report: `review-grok-round1.md`.
- Verdict: FINDINGS (6). Executor reproduced every finding with its own run (scratchpad repro through `SourceIndex::fromSources`, `Rules::resolve`, `Allowlist::fromArray`) — all six CONFIRMED.

| # | Severity | Finding | Executor action |
|---|---|---|---|
| 1 | High | anonymous-class methods share one symbol (`<file>`), migrations are `return new class` | fixed: symbol `<enclosing|<file>>@anonymous::method`; test added |
| 2 | Medium | exact-FQCN scope element matched as string prefix | fixed: only a trailing `\` or `path:` is a prefix; exact FQCN also owns `Fqcn::member`; test added |
| 3 | Medium | `config` matched any `->get/has`, `Config::set`, ignored `config(key:)` | fixed: receiver must read as a config repository, Config facade reads only, named `key`; tests added |
| 4 | Medium | `table-literal` matched any string in Schema/Repositories (`->model('activity')`), `on/constrained/DB::*` as `table` | fixed: only constants, arrays and match arms; table call set cut to the design list (`Schema::*`, `DB::table`, table/from/join*); tests added |
| 5 | Medium | `route` matched any `->route(` (`$request->route('token')`) | fixed: method `name` only; test added |
| 6 | Medium | `removed_in` unbounded by the blocking phase | fixed: I1–I3 ≤ P4, I4 ≤ P3, I4 category mandatory; `jwt-optional` allowed up to P5 (design lists it with P5 — see deviation) |

Re-review: not run (budget: one optional re-review; checks V1–V6 re-ran green after the correction). Side note from the reviewer: `scripts/t` failed in the reviewer sandbox (`ln … build/php84/bin/php: Permission denied`) — read-only sandbox artifact, not a product defect.
