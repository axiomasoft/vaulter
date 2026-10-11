# P7.1 review — grok/grok-4.7/high (session 01a12c00-7cbf-7f03-9c65-0a51d31d2d78)
Verdict: FINDINGS (4), all confirmed by the executor with its own run and fixed in one correction; no re-review (local fixes, covered by new tests).
1. Bug — `-v` put the exception message into the finding, so `--json` printed it. Fix: findings always carry the class only; the message is `DoctorCheckResult::$detail`, printed in the human output with `-v`, never serialized. Tests: DoctorRunnerTest (runner + command + JSON).
2. Bug — a failed probe delete returned `media.disk.probe_ok` and left the object. Fix: `media.disk.probe_delete_failed` (error, evidence names the object). Test: MediaDoctorChecksTest.
3. Bug — manual endpoint disable and the deliveries parked with `endpoint_disabled` were reported as failing. Fix: count `disabled_reason = failing` and dead deliveries whose `last_error` is not `endpoint_disabled`. Test: CoreDoctorChecksTest.
4. Bug (latent) — two config validators from one class collapsed to one. Fix: numbered names. Test: CoreDoctorChecksTest.
Confirmed OK by the reviewer: scan checks (NULL-safe tenant scope, UTC lease with another app timezone), quota read, tenancy in console.
Stream: review-grok-stream.jsonl; full report: review-grok-full.md
Route proof: its own session record ~/.grok/sessions/…vaulter/01a12c00-7cbf-7f03-9c65-0a51d31d2d78/updates.jsonl shows modelId=grok-4.7; effort and contextWindowTokens are not recorded there (no context capacity was requested; effort high was passed on the launch line).
