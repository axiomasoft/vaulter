<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Vaulter\Actions\Webhooks\RedeliverWebhookAction;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Webhooks\WebhookDeliveryNotRedeliverableException;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * Manual redelivery re-arms the SAME delivery row, so the receiver sees the same
 * webhook-id and can deduplicate; it needs a finished delivery and a live endpoint.
 */
function deadDelivery(array $endpoint = [], array $delivery = []): WebhookDelivery
{
    return WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint($endpoint), [
        'payload' => ['id' => 'evt-redeliver', 'type' => 'folder.created'],
        'status' => 'dead',
        'attempts' => 10,
        'next_retry_at' => null,
        'last_error' => 'HTTP 500',
        ...$delivery,
    ]);
}

it('re-arms a dead delivery in place and dispatches it after commit', function (): void {
    Bus::fake();
    $delivery = deadDelivery();
    $id = $delivery->getKey();

    $result = app(RedeliverWebhookAction::class)->execute($delivery);

    expect($result->getKey())->toBe($id)
        ->and($result->status)->toBe('pending')
        ->and($result->attempts)->toBe(0)
        ->and($result->last_error)->toBeNull()
        ->and($result->next_retry_at)->not->toBeNull()
        ->and($result->claim_token)->toBeNull()
        ->and(WebhookDelivery::query()->count())->toBe(1);
    Bus::assertDispatched(DeliverWebhook::class, 1);
});

it('sends the same webhook-id again and delivers', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $delivery = deadDelivery();

    app(RedeliverWebhookAction::class)->execute($delivery);
    WebhookTestHelpers::deliver($delivery);

    expect($delivery->refresh()->status)->toBe('delivered')->and($delivery->attempts)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->header('webhook-id')[0] === 'evt-redeliver');
});

it('can redeliver a delivered delivery', function (): void {
    Bus::fake();
    $delivery = deadDelivery(delivery: ['status' => 'delivered', 'attempts' => 1, 'last_error' => null]);

    expect(app(RedeliverWebhookAction::class)->execute($delivery)->status)->toBe('pending');
});

it('refuses a delivery that is still pending or failed', function (string $status): void {
    Bus::fake();
    $delivery = deadDelivery(delivery: ['status' => $status, 'attempts' => 1]);

    expect(fn () => app(RedeliverWebhookAction::class)->execute($delivery))
        ->toThrow(WebhookDeliveryNotRedeliverableException::class, "it is [{$status}]");
    expect($delivery->refresh()->attempts)->toBe(1);
    Bus::assertNothingDispatched();
})->with(['pending', 'failed']);

it('refuses a delivery whose endpoint is inactive', function (): void {
    Bus::fake();
    $delivery = deadDelivery(endpoint: ['is_active' => false]);

    expect(fn () => app(RedeliverWebhookAction::class)->execute($delivery))
        ->toThrow(WebhookDeliveryNotRedeliverableException::class, 'endpoint is inactive');
    expect($delivery->refresh()->status)->toBe('dead');
    Bus::assertNothingDispatched();
});

it('maps the refusal to webhook_delivery_not_redeliverable / 409', function (): void {
    $exception = WebhookDeliveryNotRedeliverableException::notTerminal(1, 'pending');

    expect($exception)->toBeInstanceOf(ProblemException::class)
        ->and($exception->problemCode())->toBe('webhook_delivery_not_redeliverable')
        ->and($exception->httpStatus())->toBe(409)
        ->and($exception->getMessage())->toContain('Docs:')->toContain('#webhook-delivery-not-redeliverable');
});

it('redelivers one delivery by id from the command', function (): void {
    Bus::fake();
    $delivery = deadDelivery();

    $this->artisan('vaulter:webhooks:redeliver', ['delivery' => (string) $delivery->getKey()])
        ->expectsOutputToContain('Redelivered 1 of 1')
        ->assertSuccessful();

    expect($delivery->refresh()->status)->toBe('pending');
});

it('redelivers dead deliveries in bulk, filtered by endpoint and age', function (): void {
    Bus::fake();
    $wanted = deadDelivery();
    $sameEndpointOld = WebhookTestHelpers::delivery($wanted->endpoint, ['status' => 'dead', 'attempts' => 10, 'next_retry_at' => null]);
    $sameEndpointOld->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
    $otherEndpoint = deadDelivery(['url' => 'https://other.test/hook']);
    $delivered = WebhookTestHelpers::delivery($wanted->endpoint, ['status' => 'delivered', 'attempts' => 1, 'next_retry_at' => null]);

    $this->artisan('vaulter:webhooks:redeliver', ['--endpoint' => (string) $wanted->endpoint_id, '--since' => now()->subDay()->toDateTimeString()])
        ->expectsOutputToContain('Redelivered 1 of 1')
        ->assertSuccessful();

    expect($wanted->refresh()->status)->toBe('pending')
        ->and($sameEndpointOld->refresh()->status)->toBe('dead')
        ->and($otherEndpoint->refresh()->status)->toBe('dead')
        ->and($delivered->refresh()->status)->toBe('delivered');
});

it('redelivers every dead delivery when no filter is given', function (): void {
    Bus::fake();
    $first = deadDelivery();
    $second = deadDelivery(['url' => 'https://other.test/hook']);

    $this->artisan('vaulter:webhooks:redeliver')->expectsOutputToContain('Redelivered 2 of 2')->assertSuccessful();

    expect($first->refresh()->status)->toBe('pending')->and($second->refresh()->status)->toBe('pending');
});

it('reports a refused delivery and fails when nothing could be sent', function (): void {
    Bus::fake();
    $delivery = deadDelivery(endpoint: ['is_active' => false]);

    $this->artisan('vaulter:webhooks:redeliver', ['delivery' => (string) $delivery->getKey()])
        ->expectsOutputToContain('endpoint is inactive')
        ->assertFailed();
});

it('rejects an unknown --status and an invalid --since', function (): void {
    $this->artisan('vaulter:webhooks:redeliver', ['--status' => 'pending'])->assertFailed();
    $this->artisan('vaulter:webhooks:redeliver', ['--since' => 'not a date'])->assertFailed();
});

it('does nothing when the webhooks feature is off', function (): void {
    config()->set('vaulter.features.webhooks', false);
    $delivery = deadDelivery();

    $this->artisan('vaulter:webhooks:redeliver')->expectsOutputToContain('Webhooks feature is off')->assertSuccessful();

    expect($delivery->refresh()->status)->toBe('dead');
});
