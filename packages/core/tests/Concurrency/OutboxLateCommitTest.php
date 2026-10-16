<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookFanoutConsumer;

/**
 * O2 on PostgreSQL (D29): a long transaction takes a smaller outbox seq and commits after a shorter one with a
 * bigger seq. Reconciliation only trusts events older than the settle window, so it does not move past the
 * smaller seq while it may still commit, and both events get their delivery row once they settled. One
 * reconciler per consumer (O6): two relays racing for the consumer lease get one generation between them.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
uses(EnablesWebhooksModule::class);

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.outbox.settle_window_seconds', 5);
});

it('does not reconcile past a smaller seq that commits late inside the settle window', function (): void {
    app(OutboxRelay::class)->pass();
    $activation = app(OutboxStore::class)->consumer(WebhookFanoutConsumer::ID)['reconciled_through_seq'];

    $outcomes = ForkRace::run([
        // A long transaction: writes its event (the smaller seq), commits two seconds later.
        static function (): string {
            return DB::transaction(static function (): string {
                $event = WebhookTestHelpers::outbox(['event_id' => 'late'], consumer: null);
                usleep(2_000_000);

                return (string) $event->outbox_seq;
            });
        },
        // A short one: writes the bigger seq at once, then a relay reconciles while the first is still open.
        static function (): array {
            usleep(500_000);
            $event = WebhookTestHelpers::outbox(['event_id' => 'early'], consumer: null);
            usleep(500_000);
            app(OutboxRelay::class)->pass();

            return ['seq' => $event->outbox_seq, 'reconciled' => app(OutboxStore::class)->consumer(WebhookFanoutConsumer::ID)['reconciled_through_seq']];
        },
    ], timeoutMs: 20_000);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and((int) $outcomes[0]->value)->toBeLessThan($outcomes[1]->value['seq'])
        ->and($outcomes[1]->value['reconciled'])->toBe($activation)
        ->and(WebhookTestHelpers::outboxDelivery('late'))->toBeNull();

    // Once both settled, one pass materializes both rows.
    $this->travel(10)->seconds();
    app(OutboxRelay::class)->pass();
    expect(WebhookTestHelpers::outboxDelivery('late'))->not->toBeNull()
        ->and(WebhookTestHelpers::outboxDelivery('early'))->not->toBeNull()
        ->and(app(OutboxStore::class)->consumer(WebhookFanoutConsumer::ID)['reconciled_through_seq'])->toBe($outcomes[1]->value['seq']);
});

it('gives the consumer lease to one of two racing reconcilers', function (): void {
    app(OutboxRelay::class)->pass();

    $outcomes = ForkRace::run(array_fill(0, 2, static function (): ?int {
        return app(OutboxStore::class)->leaseConsumer(WebhookFanoutConsumer::ID, 'relay-'.getmypid(), CarbonImmutable::now(), 60);
    }));

    $generations = array_values(array_filter(array_map(static fn (RaceOutcome $outcome): mixed => $outcome->value, $outcomes), static fn (mixed $value): bool => $value !== null));
    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true, true])
        ->and($generations)->toHaveCount(1);
});
