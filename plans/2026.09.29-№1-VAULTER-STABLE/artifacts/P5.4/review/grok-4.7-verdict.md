**FINDINGS**

## Major — `packages/core/src/Webhooks/StandardWebhookVerifier.php:33`

`verify()` keeps only the first value of each header, then matches every `v1` signature against that one timestamp. The call both guides tell receivers to make, `$request->headers->all()`, is a Symfony bag of lists. A second `webhook-timestamp` / `webhook-signature` line is therefore verified against a timestamp the tolerance check never saw.

Failure: a receiver using the documented call accepts a captured delivery whose timestamp is a day old, as long as the request also carries a fresh `webhook-timestamp` first. Tolerance both ways does not apply to the signature that actually matches. The official Standard Webhooks PHP verifier signs the timestamp it accepted and compares that to each signature in that same header.

REPRODUCTION (SQLite Pest, exit 0, stderr `replay_rejected=false`):

```php
$secret = WebhookSecret::generate();
$body = '{"id":"evt-1"}';
$now = 1_700_000_000;
$good = (new WebhookSigner)->sign('evt-1', $now, $body, $secret);
$request = Request::create('/hook', 'POST', server: [
    'HTTP_WEBHOOK_ID' => 'evt-1',
    'HTTP_WEBHOOK_TIMESTAMP' => (string) $now,
    'HTTP_WEBHOOK_SIGNATURE' => $good,
], content: $body);

// The documented call accepts this honest request.
(new StandardWebhookVerifier)->verify($request->headers->all(), $request->getContent(), [$secret], now: $now);

$stale = $now - 86_400;
$request->headers->set('webhook-timestamp', (string) $stale, false);
$request->headers->set('webhook-signature', (new WebhookSigner)->sign('evt-1', $stale, $body, $secret), false);

// Expected: InvalidWebhookSignatureException. Actual: returns.
(new StandardWebhookVerifier)->verify(
    $request->headers->all(), $request->getContent(), [$secret], toleranceSeconds: 300, now: $now,
);
```

Observed: `replay_ts=["1700000000","1699913600"]`, `replay_rejected=false`.

## Major — `packages/core/src/Actions/Webhooks/UpdateWebhookEndpointAction.php:45` and `WebhookEndpointStoreRepository.php:77`

Deactivation is decided from the in-memory `is_active`, with no row lock. `killOpenForEndpoint` runs only when that copy was active. A model loaded while the endpoint was off, then used to set `is_active=false` after a re-enable, writes `disabled_reason=manual` and leaves every new pending/failed delivery pending.

`DeliverWebhook` (`DeliverWebhook.php:97`) never checks `is_active` before POST. The queued job claims the surviving row and delivers it to the endpoint that was just switched off.

REPRODUCTION (SQLite Pest failed: `Failed asserting that true is false` at the `is_active` expectation — the row is inactive and the delivery is still `pending`):

```php
$endpoint = WebhookTestHelpers::endpoint([
    'is_active' => false, 'disabled_reason' => 'manual', 'disabled_at' => now(),
]);
$loadedWhileOff = WebhookEndpoint::query()->findOrFail($endpoint->getKey());

app(UpdateWebhookEndpointAction::class)->execute(
    $endpoint->fresh(),
    new UpdateWebhookEndpointInput(url: null, events: null, isActive: true),
);
$open = WebhookTestHelpers::delivery($endpoint->fresh());

app(UpdateWebhookEndpointAction::class)->execute(
    $loadedWhileOff,
    new UpdateWebhookEndpointInput(url: null, events: null, isActive: false),
);

expect($endpoint->fresh()->is_active)->toBeFalse()
    ->and($open->refresh()->status)->toBe('dead'); // actual: pending
```

## Major — `packages/core/src/Actions/Webhooks/RedeliverWebhookAction.php:33` and `DeliverWebhook.php:103`

Redeliver locks the delivery, checks the endpoint is active, then saves `pending` and dispatches `DeliverWebhook` after commit. Deactivate locks nothing and kills only rows that are already `pending` or `failed`. If deactivation commits while redelivery still sees `dead`, the kill misses the row. The save then publishes `pending` and the after-commit job runs.

The job claims any pending row and POSTs without reading `is_active`. The receiver gets the event after the endpoint was switched off.

REPRODUCTION (SQLite Pest failed: `Failed asserting that 1 is identical to 0` — one POST was sent):

```php
$sent = 0;
Http::fake(function () use (&$sent) {
    $sent++;
    return Http::response('', 200);
});
$delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), [
    'payload' => ['id' => 'evt-race', 'type' => 'folder.created'],
    'status' => 'dead', 'attempts' => 4, 'next_retry_at' => null, 'last_error' => 'HTTP 500',
]);

WebhookDelivery::updating(function (WebhookDelivery $row): void {
    if ($row->status !== 'pending' || $row->getOriginal('status') !== 'dead') {
        return;
    }
    app(DeactivateWebhookEndpointAction::class)->execute($row->endpoint()->firstOrFail());
});

app(RedeliverWebhookAction::class)->execute($delivery);

expect($delivery->fresh()->endpoint->is_active)->toBeFalse()
    ->and($sent)->toBe(0); // actual: 1
```

Checked and not reported: signature covers the exact `json_encode` body (Laravel HTTP tests pass); `hash_equals` on every candidate, including a short `v1` value; unusable secrets are skipped; tolerance is inclusive both ways for a single timestamp; removed config keys fail boot; migration conversion is idempotent and rolls back inside the migrator transaction on SQLite (1001 rows, decrypt failure on the last, first secret unchanged). PostgreSQL deadlock order and the auto-disable race were not executed.