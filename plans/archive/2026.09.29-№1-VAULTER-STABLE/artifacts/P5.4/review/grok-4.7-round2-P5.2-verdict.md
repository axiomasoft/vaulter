# grok/grok-4.7 session 01a123f0-1a5b-76e1-9425-2d14704c01d0

VERDICT: FINDINGS

### P5.2-1
- **Severity:** major
- **File:** `packages/core/src/Events/Access/NodeInheritanceChanged.php:48`
- **Defect:** `access.inheritance_changed` publishes the node's current `sequence` even though this mutation does not advance `v_nodes.sequence`. The catalogue in `P5-design-events.md` marks that column `—`, the same as `grant.created` / `grant.revoked`, whose `sequence()` stays null. `docs/guide/webhooks.md` tells receivers to drop a delivery whose `sequence` is not newer than the last applied node sequence. An inheritance change therefore shares the sequence of the previous node mutation and is discarded as stale, while `inherit_grants` has actually changed. `InheritanceChangedEventTest` asserts `toBeInt()`, so the suite locks this in. The outbox `sequence` column is the same value (`OutboxRecorder` copies `$event->sequence()`).
- **Reproduction:** Scratch Pest file under `/tmp` (removed after the run). `scripts/php84` could not create `build/php84/bin/php` (`Permission denied`), so the run used `/usr/bin/php8.4`:

```text
env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: /usr/bin/php8.4 vendor/bin/pest --ci /tmp/p52-review-test.php
```

```text
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
     'node_sequence_before' => 0,
     'node_sequence_after' => 0,
-    'envelope_sequence' => null,
+    'envelope_sequence' => 0,
     'data' => Array &1 [
         'inherits' => false,
     ],
 ]
```

- **Suggested fix:** Delete the `sequence()` override so the `DomainEvent` default (`null`) is used, and expect `null` in `InheritanceChangedEventTest`. Leave `v_nodes.sequence` unchanged: access changes are not node versions, which is why `grant.created` does not bump it either.

### P5.2-2
- **Severity:** minor
- **File:** `packages/core/src/Actions/Sharing/UpdateShareLinkAction.php:85`
- **Defect:** `share.updated` is recorded whenever the request carries `maxUses`, `expiresAt`, `removeMaxUses`, or `removePassword`. Only `audience` is compared with the locked row. A repeat `maxUses: 3` leaves `max_uses` at 3 and still emits `data.changed = ["max_uses"]`. The catalogue defines `changed` as the names of fields that changed. `ShareLinkLifecycleEventsTest` only covers an empty `ShareLinkUpdate`.
- **Reproduction:** Same Pest run, second test:

```text
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
     'stored_max_uses' => 3,
-    'events' => 0,
-    'changed' => null,
+    'events' => 1,
+    'changed' => Array &1 [
+        0 => 'max_uses',
+    ],
 ]
```

- **Suggested fix:** Add `max_uses`, `expires_at`, `removeMaxUses`, and `removePassword` to `$attributes` only when the locked row differs, using the same style as the `audience` check, and record the event from that diff. A new password still counts as a change because the hash is not comparable to the plaintext.

Not covered: PostgreSQL/MySQL, the full suite, SchemaParity, e2e, and the P5.2 barrier. Journal append (P5.5) and webhook delivery/signing (P5.3/P5.4) were read against the catalogue only. Drive and documents conformance suites were not re-run.