## Summary

`vaulter:doctor` adds a tagged, read-only check runner with the catalogue from the P7.1 design: exit 0/1/2, `--production` escalation, and install step 5 still returning 0. The core, drive, media, documents, and azgard doctor suites passed (52 + 22 + 9 + 10 + 3), and NULL-safe scan scope, Tokyo-vs-UTC lease comparison, tenancy-without-HTTP, and quota reads behaved as specified. Read-only and secret redaction held on those runs except for two reproduced holes: a failed disk-probe delete is reported as success and leaves the object, and `-v` copies exception text (DSN and password) into the finding message and therefore into `--json`.

## Issues

### Issue 1 -- Severity: bug
- File: packages/media/src/Doctor/DiskProbeCheck.php:83
- Description: `media.disk.probe` is the only allowed write (`--probe-storage` or `--production`, `DoctorContext::mayProbeStorage()`). It does call `StorageDriver::delete` from `finally`, but `remove()` swallows every throwable (line 91) and the success return on line 83 still emits `media.disk.probe_ok`. A disk that accepts the PUT and then refuses the DELETE is reported healthy and keeps `.vaulter-doctor/<ulid>`. `vaulter:doctor --production` takes this path without `--probe-storage`. Reproduction: `php8.4 /tmp/grok-vostrikov/doctor-repro.php` (Testbench sqlite, `Storage::fake('local')` whose `delete()` throws). Output: `code=media.disk.probe_ok severity=ok message=Disk "local" accepts, returns and deletes an object.` and `files=[".vaulter-doctor/01M4P1N81VSCXGHTPGRWXMB0VR"]`. `MediaDoctorChecksTest` only covers a failed read whose delete still succeeds.
- Suggestion: If delete throws, or the object is still present afterwards, return `media.disk.probe_failed` (stage `delete`) and do not return ok. Keep the delete attempt in `finally` on the write/read failure paths, but surface a failed delete instead of swallowing it.
- Status: open

### Issue 2 -- Severity: bug
- File: packages/core/src/Doctor/DoctorRunner.php:49
- Description: The design says `doctor.check_failed` message is the exception class only, because `getMessage()` can hold a DSN or a secret, and that `-v` prints `getMessage` separately. `DoctorCommand` (line 52) sets `exposeMessages` from `$this->output->isVerbose()`, and the runner concatenates `$exception->getMessage()` into the finding message. That field is what `--json` writes to stdout, so the hinted `run with -v` path puts the secret in the machine-readable document. Quiet mode is redacted. Reproduction: same command as Issue 1, section `VERBOSE JSON`. Quiet stdout message is `RuntimeException` with hint `run with -v`. Verbose stdout message is `RuntimeException: password=hunter2 dsn=pgsql://vaulter:s3cret@db.internal:5432/app` and `"hint":null`. `DoctorRunnerTest` (`shows the exception message only when asked`) currently asserts this embedding.
- Suggestion: Always store only `$exception::class` in the finding message, and leave the hint as `run with -v`. When verbose, print `getMessage()` on stderr after the report, not inside the finding, so `--json` stays free of DSNs, passwords, and tokens.
- Status: open

### Issue 3 -- Severity: bug
- File: packages/core/src/Doctor/Checks/WebhooksDeliveriesCheck.php:34
- Description: The catalogue asks for auto-disabled endpoints (`disabled_reason` `failing`) and deliveries that exhausted the retry schedule. The check counts every `disabled_at` and every `status = dead`. A manual deactivate (`DeactivateWebhookEndpointAction`, `disabled_reason` `manual`) also parks open deliveries as `dead` with `last_error` `endpoint_disabled` before the schedule is spent. Doctor then says they were "disabled after failing" and hints `vaulter:webhooks:redeliver`. Reproduction: same command, section `MANUAL DISABLE + KILLED DELIVERY` (endpoint `disabled_reason=manual`, delivery `status=dead`, `attempts=1`, `last_error=endpoint_disabled`). Output: `code=core.webhooks.deliveries_failing message=1 endpoint(s) were disabled after failing and 1 delivery(ies) used up their retries.` The signing secret was not in the finding. `CoreDoctorChecksTest` only inserts `disabled_reason=failing`.
- Suggestion: Count endpoints with `disabled_reason = failing` only. Count `dead` deliveries that actually exhausted the schedule (attempts past `retry_schedule`, and not `last_error = endpoint_disabled`). Keep the warning/warning severity.
- Status: open

### Issue 4 -- Severity: bug
- File: packages/core/src/Doctor/Checks/ConfigValidCheck.php:46
- Description: Tagged `vaulter.config_validators` closures are stored in a map keyed by `ReflectionFunction::getClosureScopeClass()`, or the literal `package` when the closure has no class. A second closure from the same class overwrites the first, so a failing validator can be dropped and `core.config.valid` will not list it. The shipped providers register one closure each, so today's boot set does not collide; a package that tags two closures from its provider (the documented extension point) silently loses one. Reproduction: same command, section `VALIDATOR KEY COLLISION`. Two closures defined on `ReproDoctor` throw `InvalidConfigurationException` `first-validator-marker` and `second-validator-marker`. Output: `has_first=no has_second=yes` and `validator=ReproDoctor message=second-validator-marker`.
- Suggestion: Run every tagged closure, keyed by the container abstract (or append to a list). Do not collapse them by scope class. Non-closure services should be reported, not skipped, if they are tagged.
- Status: open
