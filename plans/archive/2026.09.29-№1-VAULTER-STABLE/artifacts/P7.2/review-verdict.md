# P7.2 review — grok/grok-4.7/high (session 01a12bce-81a2-79d1-9a16-d6e148b8affa)
Verdict: FINDINGS (2), both confirmed by executor and fixed in docs/comment; no re-review (docs-only).
1. Major — partial schedule block: ConfigNormalizer fills omitted keys with package defaults, so "missing key stays off" (docs/UPGRADE/ScheduleConfig comment) was false. Executor confirmed (normalizer fills dotted defaults). Fix: statements corrected; null is the way to turn a task off.
2. Minor — recipes/commands guides still showed host Schedule::command lines that double-schedule. Fix: notes "only with vaulter.schedule.enabled=false" en+ru.
Stream: review-grok-stream.jsonl
Route proof: session modelId=grok-4.7; effort/contextWindowTokens not recorded in updates.jsonl (no context requested).
