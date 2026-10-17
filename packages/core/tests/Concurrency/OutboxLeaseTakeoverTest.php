<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Vaulter\Events\Outbox\DeliveryState;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookFanoutConsumer;

/**
 * O3 on PostgreSQL (D29): a relay worker whose delivery lease expired is taken over by another; the late
 * completion of the first one, fenced by its stale lease generation, writes nothing and the successor's result
 * stands.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
uses(EnablesWebhooksModule::class);

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.outbox.lease_seconds', 1);
});

it('discards the completion of a worker whose lease was taken over', function (): void {
    $message = WebhookTestHelpers::outbox(['event_id' => 'takeover']);

    $outcomes = ForkRace::run([
        // Worker A leases the delivery (generation 1), stalls past its lease, then reports dead.
        static function (): array {
            $claims = app(OutboxStore::class)->claim(WebhookFanoutConsumer::ID, 'worker-a', CarbonImmutable::now(), 1, 10);
            usleep(2_500_000);

            return [
                'generation' => $claims[0]['generation'] ?? null,
                'written' => app(OutboxStore::class)->complete('takeover', WebhookFanoutConsumer::ID, $claims[0]['generation'] ?? 0, DeliveryState::Dead, CarbonImmutable::now(), 'stale worker'),
            ];
        },
        // Worker B takes the expired lease over (generation 2) and finishes the delivery.
        static function (): array {
            Bus::fake();
            usleep(1_500_000);

            return app(OutboxRelay::class)->pass();
        },
    ], timeoutMs: 20_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($outcomes[0]->value)->toBe(['generation' => 1, 'written' => false])
        ->and($outcomes[1]->value)->toBe([WebhookFanoutConsumer::ID => 1])
        ->and(WebhookTestHelpers::outboxDelivery($message->event_id))->toMatchArray(['state' => 'done', 'lease_generation' => 2, 'last_error' => null]);
});
