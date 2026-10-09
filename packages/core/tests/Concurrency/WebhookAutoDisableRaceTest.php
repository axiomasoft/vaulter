<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\Events\WebhookEndpointDisabled;

/**
 * Two workers settle a FAILED attempt of two different deliveries of one endpoint at the
 * very moment its failing run crosses disable_after_days, on PostgreSQL. The endpoint row
 * lock serialises the settlements: the endpoint is disabled once, only one worker
 * announces it, the second settlement finds its delivery already dead, and the failure
 * counter is bumped once, not twice.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.webhooks.disable_after_days', 5);
    config()->set('vaulter.webhooks.retry_jitter', 0);
});

/** Runs the delivery job against a 500 and reports how many disabled events this process heard. */
function settleFailingDelivery(string $deliveryId): array
{
    Http::fake(['*' => Http::response('', 500)]);
    $heard = 0;
    Event::listen(WebhookEndpointDisabled::class, static function () use (&$heard): void {
        $heard++;
    });

    WebhookTestHelpers::deliver(WebhookDelivery::query()->findOrFail($deliveryId));

    return ['heard' => $heard];
}

it('disables the endpoint once and announces it once when two settlements cross the threshold together', function (): void {
    $endpoint = WebhookTestHelpers::endpoint([
        'failure_count' => 3,
        // One second past the five-day threshold: any failed attempt now disables it.
        'failing_since' => now()->subDays(5)->subSecond(),
    ]);
    $first = WebhookTestHelpers::delivery($endpoint);
    $second = WebhookTestHelpers::delivery($endpoint);
    $ids = [(string) $first->getKey(), (string) $second->getKey()];

    $outcomes = ForkRace::run([
        fn (): array => settleFailingDelivery($ids[0]),
        fn (): array => settleFailingDelivery($ids[1]),
    ], timeoutMs: 30_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($outcomes[0]->value['heard'] + $outcomes[1]->value['heard'])->toBe(1);

    $fresh = WebhookEndpoint::query()->findOrFail($endpoint->getKey());
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->disabled_reason)->toBe('failing')
        ->and($fresh->failure_count)->toBe(4)
        ->and(WebhookDelivery::query()->where('endpoint_id', $endpoint->getKey())->pluck('status')->unique()->all())->toBe(['dead'])
        ->and(WebhookDelivery::query()->where('endpoint_id', $endpoint->getKey())->whereNotNull('claim_token')->count())->toBe(0);
});

it('does not disable an endpoint whose run is still short when two settlements land together', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 1, 'failing_since' => now()->subDays(4)]);
    $first = WebhookTestHelpers::delivery($endpoint);
    $second = WebhookTestHelpers::delivery($endpoint);
    $ids = [(string) $first->getKey(), (string) $second->getKey()];

    $outcomes = ForkRace::run([
        fn (): array => settleFailingDelivery($ids[0]),
        fn (): array => settleFailingDelivery($ids[1]),
    ], timeoutMs: 30_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($outcomes[0]->value['heard'] + $outcomes[1]->value['heard'])->toBe(0);

    $fresh = WebhookEndpoint::query()->findOrFail($endpoint->getKey());
    // Both failed attempts are counted: the lock serialises them, it does not drop one.
    expect($fresh->is_active)->toBeTrue()->and($fresh->failure_count)->toBe(3);
});
