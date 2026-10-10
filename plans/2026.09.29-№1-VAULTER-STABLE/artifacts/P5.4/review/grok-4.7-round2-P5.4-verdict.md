# grok/grok-4.7 session 01a123f0-1a6c-7b80-a68c-4049a7e30afa

VERDICT: FINDINGS

The correction closes the three cases it added tests for: a repeated `webhook-timestamp` with a different value is rejected, Update/Deactivate/Redeliver lock the endpoint before the delivery, and a job whose endpoint is already inactive does not POST. Two decisions still use the endpoint row loaded at the start of the job, before that lock.

### P5.4-R1
- **Severity:** major
- **Location:** `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:106`
- **Defect:** The inactive-endpoint kill uses `$delivery->endpoint` from line 97 and then updates the delivery with no `lockForUpdate` on the endpoint. Settlement, deactivation, update, and redelivery all decide from the endpoint row locked first. This path does not. A re-enable that commits after that read and before the update still matches `pending`/`failed` and marks the delivery `dead` with `last_error = endpoint_disabled`. Nothing is sent, and a later sweep will not retry a dead row. The endpoint is active again.
- **Reproduction:** SQLite Pest (PHP 8.4.26; `scripts/php84` could not create `build/php84/bin/php`). The hook commits the re-enable inside `WebhookEndpoint::retrieved`, which is after the SELECT and before the `is_active` branch:

```php
WebhookEndpoint::retrieved(function (WebhookEndpoint $row) use ($endpoint): void {
    if ((int) $row->getKey() !== (int) $endpoint->getKey() || $row->is_active) {
        return;
    }
    WebhookEndpoint::query()->whereKey($row->getKey())->toBase()->update([
        'is_active' => 1, 'disabled_reason' => null, 'disabled_at' => null, 'failing_since' => null,
    ]);
});
WebhookTestHelpers::deliver($delivery);
```

```text
-    'sent' => 1,
+    'sent' => 0,
     'endpoint_active' => true,
-    'delivery_status' => 'delivered',
-    'last_error' => null,
+    'delivery_status' => 'dead',
+    'last_error' => 'endpoint_disabled',
```

- **Suggested fix:** Make the same transaction as `settle()`: `lockForUpdate` the endpoint, then branch on that locked `is_active`. If it is false, mark this delivery dead in that transaction. If it is true, claim the delivery before commit and only then POST. A re-enable that commits first is then visible and the delivery is sent. One that waits until after the kill leaves a dead row, which re-enable already does not resurrect.

### P5.4-R2
- **Severity:** minor
- **Location:** `packages/core/src/Jobs/Webhooks/DeliverWebhook.php:231`
- **Defect:** `signatureHeader()` signs with `activeSigningSecrets()` on the same pre-claim model from line 97. P5-I12 says the delivery reads secrets at signing time, and `overlap = 0` must not keep the previous secret. A rotation that commits during claim or DNS pinning is already stored (`previous_signing_secret` is null) while the POST is still signed with the revoked key. A receiver that kept only the new secret rejects the attempt. The leaked key is on the wire once more.
- **Reproduction:** Same SQLite Pest run. The hook calls `RotateWebhookSecretAction` with `overlapSeconds: 0` from `retrieved`, then the job signs the model it already holds:

```text
     'previous_stored' => null,
-    'old_secret_verifies' => false,
-    'new_secret_verifies' => true,
+    'old_secret_verifies' => true,
+    'new_secret_verifies' => false,
     'signature_count' => 1,
```

- **Suggested fix:** Reload the endpoint's signing columns immediately before `signatureHeader()`. Overlap 0 then contributes only the current secret. The reload does not need to be held across the HTTP call. A rotation that commits after that reload and before the bytes are written can still sign with the old key for that one send. The retry loads the row again.

Not covered: PostgreSQL lock scheduling. SQLite ignores `lockForUpdate`, so `WebhookAutoDisableRaceTest`, the retry sweep's delivery `FOR UPDATE` against endpoint-then-delivery settlement (including a foreign-key key-share cycle), and a fan-out insert committing after deactivation were not run. The 0.2 fixture migration path is PostgreSQL-only. A SQLite up/down/up of `2026_10_10_000003_webhook_endpoints_v1` on one legacy secret round-tripped.