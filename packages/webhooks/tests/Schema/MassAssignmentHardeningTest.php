<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * Mass-assignment hardening of the webhook models (moved from the drive suite with the models):
 * a service/security column can not be set through fill(), so a future `Model::create($request->validated())`
 * cannot leak a caller-chosen value into signing_secret or the delivery bookkeeping.
 *
 *  - WebhookEndpoint has a CLOSED $fillable list (url/events/is_active): a key outside it is silently discarded;
 *  - WebhookDelivery is fully `$guarded = ['*']`: ANY fill() with a non-empty array throws MassAssignmentException.
 */
it('discards a service/security column via fill() on the closed-$fillable WebhookEndpoint', function (
    string $model,
    string $dangerousKey,
    mixed $dangerousValue,
): void {
    $instance = new $model;
    $instance->fill([$dangerousKey => $dangerousValue]);

    expect($instance->getAttributes())->not->toHaveKey($dangerousKey);
})->with([
    'WebhookEndpoint.signing_secret' => [WebhookEndpoint::class, 'signing_secret', 'ATTACKER-SECRET'],
    'WebhookEndpoint.failure_count' => [WebhookEndpoint::class, 'failure_count', 999],
    'WebhookEndpoint.previous_signing_secret' => [WebhookEndpoint::class, 'previous_signing_secret', 'ATTACKER-SECRET'],
]);

it('rejects ANY fill() attribute on the fully-$guarded WebhookDelivery with MassAssignmentException', function (
    string $model,
    string $dangerousKey,
    mixed $dangerousValue,
): void {
    $instance = new $model;

    expect(fn () => $instance->fill([$dangerousKey => $dangerousValue]))
        ->toThrow(MassAssignmentException::class);
})->with([
    'WebhookDelivery.status' => [WebhookDelivery::class, 'status', 'delivered'],
    'WebhookDelivery.attempts' => [WebhookDelivery::class, 'attempts', 0],
]);

/**
 * `WebhookEndpoint::fill()` must never let a caller set the HMAC
 * signing_secret — the whole delivery-trust channel depends on it staying
 * server-generated (RegisterWebhookEndpointAction::execute()). WebhookEndpoint
 * carries a closed $fillable list (url/events/is_active), so the key is silently
 * discarded; the column is NOT NULL, so a fill()-only create() that drops it
 * fails the INSERT — a second, DB-level proof.
 */
it('never mass-assigns signing_secret on WebhookEndpoint via fill() or create()', function (): void {
    $filled = new WebhookEndpoint;
    $filled->fill([
        'url' => 'https://hooks.test/receive',
        'signing_secret' => 'ATTACKER-CHOSEN-SECRET',
    ]);

    expect($filled->getAttributes())->not->toHaveKey('signing_secret')
        ->and($filled->url)->toBe('https://hooks.test/receive');

    // 'events' is included so signing_secret is the ONLY column the guard drops —
    // isolating the NOT NULL failure to the attribute this test pins.
    // PostgreSQL marks the current transaction failed after a constraint error.
    // Let Laravel observe the exception inside a nested transaction so it rolls
    // back to the savepoint before Pest catches the expected QueryException.
    expect(fn () => DB::transaction(
        fn (): WebhookEndpoint => WebhookEndpoint::query()->create([
            'url' => 'https://hooks.test/receive-2',
            'events' => ['folder.created'],
            'signing_secret' => 'ATTACKER-CHOSEN-SECRET-2',
        ]),
    ))->toThrow(QueryException::class);
});
