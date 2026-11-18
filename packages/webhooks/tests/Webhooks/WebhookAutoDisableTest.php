<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Webhooks\Actions\DeactivateWebhookEndpointAction;
use Vaulter\Webhooks\Actions\UpdateWebhookEndpointAction;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;
use Vaulter\Webhooks\Events\WebhookEndpointDisabled;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * An endpoint whose deliveries fail without a single success for disable_after_days is
 * switched off, its open deliveries die, and the host hears about it after the commit.
 * Any success restarts the clock; a manual switch-off kills the deliveries too.
 */
beforeEach(function (): void {
    config()->set('vaulter-webhooks.disable_after_days', 5);
    config()->set('vaulter-webhooks.retry_jitter', 0);
    // Long schedule: the delivery under test keeps failing without dying.
    config()->set('vaulter-webhooks.retry_schedule', array_fill(0, 40, 60));
});

it('starts the failing run on the first failed attempt and keeps the endpoint active before the threshold', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    $this->freezeTime(function () use ($endpoint, $delivery): void {
        WebhookTestHelpers::deliver($delivery);
        expect($endpoint->refresh()->failing_since->getTimestamp())->toBe(now()->getTimestamp());
    });

    $this->travel(4)->days();
    WebhookTestHelpers::deliver($delivery);

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeTrue()
        ->and($endpoint->disabled_at)->toBeNull()
        ->and($endpoint->disabled_reason)->toBeNull()
        ->and($endpoint->failure_count)->toBe(2);
});

it('a success resets the run so the same silence does not disable the endpoint', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $status = 500;
    // A later Http::fake() would not override the first stub, so the status is a variable.
    Http::fake(['https://hooks.test/*' => function () use (&$status) {
        return Http::response('', $status);
    }]);

    WebhookTestHelpers::deliver($delivery);
    $this->travel(4)->days();

    $status = 200;
    WebhookTestHelpers::deliver($delivery);
    expect($endpoint->refresh()->failing_since)->toBeNull()
        ->and($endpoint->failure_count)->toBe(0);

    // Six days later a single failure starts a NEW run: nothing is disabled.
    $this->travel(6)->days();
    $status = 500;
    WebhookTestHelpers::deliver(WebhookTestHelpers::delivery($endpoint));

    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->failing_since->getTimestamp())->toBeGreaterThan(now()->subMinute()->getTimestamp());
});

it('disables the endpoint after five days and one second of unbroken failure and kills its open deliveries', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $other = WebhookTestHelpers::endpoint(['url' => 'https://other.test/hook']);
    $failing = WebhookTestHelpers::delivery($endpoint);
    $pending = WebhookTestHelpers::delivery($endpoint);
    $failed = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 2, 'next_retry_at' => now()->addMinute()]);
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1, 'next_retry_at' => null]);
    $unrelated = WebhookTestHelpers::delivery($other);

    WebhookTestHelpers::deliver($failing);
    $failingSince = $endpoint->refresh()->failing_since;

    $this->travel(5)->days();
    $this->travel(1)->seconds();
    WebhookTestHelpers::deliver($failing);

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe('failing')
        ->and($endpoint->disabled_at)->not->toBeNull()
        ->and($endpoint->failing_since->getTimestamp())->toBe($failingSince->getTimestamp());

    foreach ([$failing, $pending, $failed] as $row) {
        $row->refresh();
        expect($row->status)->toBe('dead')
            ->and($row->last_error)->toBe('endpoint_disabled')
            ->and($row->next_retry_at)->toBeNull()
            ->and($row->claim_token)->toBeNull();
    }
    expect($delivered->refresh()->status)->toBe('delivered')
        ->and($unrelated->refresh()->status)->toBe('pending')
        ->and($other->refresh()->is_active)->toBeTrue();
});

it('never disables with disable_after_days = 0', function (): void {
    config()->set('vaulter-webhooks.disable_after_days', 0);
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    WebhookTestHelpers::deliver($delivery);
    $this->travel(400)->days();
    WebhookTestHelpers::deliver($delivery);

    expect($endpoint->refresh()->is_active)->toBeTrue();
});

it('dispatches WebhookEndpointDisabled once, after the disabling transaction committed', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    WebhookTestHelpers::deliver($delivery);

    $baseline = DB::connection()->transactionLevel();
    $heard = [];
    Event::listen(WebhookEndpointDisabled::class, static function (WebhookEndpointDisabled $event) use (&$heard): void {
        $heard[] = [
            'level' => DB::connection()->transactionLevel(),
            'active' => DB::table(app(DatabaseOptions::class)->table('webhook_endpoints'))->where('id', $event->endpointId)->value('is_active'),
            'event' => $event,
        ];
    });

    $failingSince = $endpoint->refresh()->failing_since;
    $this->travel(5)->days();
    WebhookTestHelpers::deliver($delivery);

    expect($heard)->toHaveCount(1)
        // Outside the settlement transaction, with the disabling already visible.
        ->and($heard[0]['level'])->toBe($baseline)
        ->and((bool) $heard[0]['active'])->toBeFalse()
        ->and($heard[0]['event']->endpointId)->toBe($endpoint->id)
        ->and($heard[0]['event']->reason)->toBe('failing')
        ->and($heard[0]['event']->failingSince->getTimestamp())->toBe($failingSince->getTimestamp());
});

it('dispatches no event while the endpoint stays active', function (): void {
    Event::fake([WebhookEndpointDisabled::class]);
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());

    WebhookTestHelpers::deliver($delivery);

    Event::assertNotDispatched(WebhookEndpointDisabled::class);
});

it('does not touch a delivery that dies from the disabling while its job was in flight', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    Http::fake(function () use ($endpoint) {
        // The owner switches the endpoint off while the POST is on the wire.
        app(DeactivateWebhookEndpointAction::class)->execute($endpoint->fresh());

        return Http::response('', 200);
    });

    WebhookTestHelpers::deliver($delivery);

    // The job lost its lease to the disabling: the dead verdict stands, no late "delivered".
    expect($delivery->refresh()->status)->toBe('dead')
        ->and($delivery->last_error)->toBe('endpoint_disabled')
        ->and($endpoint->refresh()->disabled_reason)->toBe('manual')
        ->and($endpoint->last_triggered_at)->toBeNull();
});

it('deactivating an endpoint records the manual reason and kills open deliveries', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 4, 'failing_since' => now()->subDay()]);
    $open = WebhookTestHelpers::delivery($endpoint);
    $failed = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1]);
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1, 'next_retry_at' => null]);

    app(DeactivateWebhookEndpointAction::class)->execute($endpoint);

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe('manual')
        ->and($endpoint->disabled_at)->not->toBeNull()
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->failing_since)->toBeNull()
        ->and($open->refresh()->status)->toBe('dead')
        ->and($open->last_error)->toBe('endpoint_disabled')
        ->and($failed->refresh()->status)->toBe('dead')
        ->and($delivered->refresh()->status)->toBe('delivered');
});

it('switching an endpoint off through update behaves like deactivating, and back on starts clean', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 2, 'failing_since' => now()->subDay()]);
    $open = WebhookTestHelpers::delivery($endpoint);

    app(UpdateWebhookEndpointAction::class)->execute($endpoint, new UpdateWebhookEndpointInput(isActive: false));

    expect($endpoint->refresh()->disabled_reason)->toBe('manual')
        ->and($open->refresh()->status)->toBe('dead');

    app(UpdateWebhookEndpointAction::class)->execute($endpoint, new UpdateWebhookEndpointInput(isActive: true));

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeTrue()
        ->and($endpoint->failing_since)->toBeNull()
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->disabled_at)->toBeNull()
        ->and($endpoint->disabled_reason)->toBeNull()
        // Deliveries that died stay dead; they are redelivered on purpose.
        ->and($open->refresh()->status)->toBe('dead');
});

it('re-enabling an auto-disabled endpoint clears the failing run and the reason', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    WebhookTestHelpers::deliver($delivery);
    $this->travel(5)->days();
    WebhookTestHelpers::deliver($delivery);
    expect($endpoint->refresh()->disabled_reason)->toBe('failing');

    app(UpdateWebhookEndpointAction::class)->execute($endpoint, new UpdateWebhookEndpointInput(isActive: true));

    $endpoint->refresh();
    expect($endpoint->is_active)->toBeTrue()
        ->and($endpoint->failing_since)->toBeNull()
        ->and($endpoint->disabled_at)->toBeNull()
        ->and($endpoint->disabled_reason)->toBeNull()
        ->and($endpoint->failure_count)->toBe(0);
});

it('kills the deliveries created since when a stale inactive copy of the endpoint is switched off again', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['is_active' => false, 'disabled_reason' => 'manual', 'disabled_at' => now()]);
    $loadedWhileOff = $endpoint->newQuery()->findOrFail($endpoint->getKey());

    app(UpdateWebhookEndpointAction::class)->execute($endpoint->fresh(), new UpdateWebhookEndpointInput(isActive: true));
    $open = WebhookTestHelpers::delivery($endpoint->fresh());

    app(UpdateWebhookEndpointAction::class)->execute($loadedWhileOff, new UpdateWebhookEndpointInput(isActive: false));

    expect($endpoint->fresh()->is_active)->toBeFalse()
        ->and($endpoint->fresh()->disabled_reason)->toBe('manual')
        ->and($open->refresh()->status)->toBe('dead')
        ->and($open->last_error)->toBe('endpoint_disabled');
});

it('sends nothing to an endpoint that is off and lets the delivery die', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(['is_active' => false]));

    WebhookTestHelpers::deliver($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('dead')->and($delivery->last_error)->toBe('endpoint_disabled');
});
