<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Webhooks\Actions\RedeliverWebhookAction;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookFanoutConsumer;

/**
 * Prune deletes only finished work: an outbox event only once no delivery of it is open and the
 * consumers that hold retention reconciled past it (vaulter:outbox:prune), a webhook delivery
 * only once delivered or dead (vaulter:webhooks:prune). An event with an open delivery — including
 * one whose fan-out stopped half way — and a pending/failed delivery survive any age.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-30 12:00:00');
});

function agePruneRow(WebhookDelivery $row, Carbon $at): void
{
    DB::table($row->getTable())->where('id', $row->getKey())->update(['created_at' => $at, 'updated_at' => $at]);
}

/** Marks the webhooks.fanout delivery of $event done, as a finished fan-out leaves it. */
function finishFanout(OutboxEvent $event): void
{
    DB::table(app(DatabaseOptions::class)->table(OutboxStore::DELIVERIES))->where('event_id', $event->event_id)
        ->update(['state' => 'done', 'done_at' => Carbon::now()]);
}

/** Registers webhooks.fanout after the events were written: it holds nothing below the current seq. */
function registerFanout(): void
{
    app(OutboxStore::class)->register([WebhookFanoutConsumer::ID => DeliveryClass::Durable], CarbonImmutable::now());
}

it('never prunes an outbox event with an open delivery, however old, even half fanned out', function (): void {
    config()->set('vaulter.outbox.retention_seconds', 60);
    $ancient = Carbon::now()->subYears(3);
    $untouched = WebhookTestHelpers::outbox(['created_at' => $ancient]);
    $halfway = WebhookTestHelpers::outbox(['created_at' => $ancient]);
    DB::table(app(DatabaseOptions::class)->table(OutboxStore::DELIVERIES))->where('event_id', $halfway->event_id)
        ->update(['state' => 'leased', 'continuation' => '7', 'lease_generation' => 3, 'lease_expires_at' => $ancient]);
    $finished = WebhookTestHelpers::outbox(['created_at' => Carbon::now()->subDay()]);
    finishFanout($finished);
    registerFanout();

    Artisan::call('vaulter:outbox:prune');

    expect(OutboxEvent::query()->whereKey($untouched->getKey())->exists())->toBeTrue()
        ->and(OutboxEvent::query()->whereKey($halfway->getKey())->exists())->toBeTrue()
        ->and(OutboxEvent::query()->whereKey($finished->getKey())->exists())->toBeFalse()
        ->and(WebhookTestHelpers::outboxDelivery($finished->event_id))->toBeNull();
});

it('never prunes a pending or failed delivery, however old', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $ancient = Carbon::now()->subYears(3);
    $pending = WebhookTestHelpers::delivery($endpoint, ['event_id' => 'p']);
    $failed = WebhookTestHelpers::delivery($endpoint, ['event_id' => 'f', 'status' => 'failed', 'attempts' => 4]);
    $delivered = WebhookTestHelpers::delivery($endpoint, ['event_id' => 'd', 'status' => 'delivered', 'next_retry_at' => null]);
    $dead = WebhookTestHelpers::delivery($endpoint, ['event_id' => 'x', 'status' => 'dead', 'next_retry_at' => null]);
    foreach ([$pending, $failed, $delivered, $dead] as $row) {
        agePruneRow($row, $ancient);
    }

    Artisan::call('vaulter:webhooks:prune', ['--days' => '0']);

    expect(WebhookDelivery::query()->pluck('event_id')->sort()->values()->all())->toBe(['f', 'p']);
});

it('keeps a finished outbox event inside the retention window', function (): void {
    config()->set('vaulter.outbox.retention_seconds', 30 * 86400);
    $recent = WebhookTestHelpers::outbox(['created_at' => Carbon::now()->subDays(29)]);
    $old = WebhookTestHelpers::outbox(['created_at' => Carbon::now()->subDays(31)]);
    registerFanout();
    finishFanout($recent);
    finishFanout($old);

    Artisan::call('vaulter:outbox:prune');

    expect(OutboxEvent::query()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(OutboxEvent::query()->whereKey($old->getKey())->exists())->toBeFalse();
});

it('keeps an outbox event that a replay reopened between the select and the delete', function (): void {
    config()->set('vaulter.outbox.retention_seconds', 60);
    $row = WebhookTestHelpers::outbox(['created_at' => Carbon::now()->subDays(40)]);
    registerFanout();
    finishFanout($row);
    $replayed = false;
    DB::beforeExecuting(function (string $sql) use ($row, &$replayed): void {
        if (!$replayed && str_starts_with($sql, 'delete from') && str_contains($sql, $row->getTable())) {
            $replayed = true;
            DB::table(app(DatabaseOptions::class)->table(OutboxStore::DELIVERIES))->where('event_id', $row->event_id)->update(['state' => 'pending']);
        }
    });

    $pruned = app(OutboxRelay::class)->prune();

    expect($replayed)->toBeTrue()
        ->and($pruned['events'])->toBe(0)
        ->and(OutboxEvent::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('keeps a delivery redelivered between the batch select and delete', function (string $status, bool $finished): void {
    Bus::fake();
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $row = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), [
        'status' => $status, 'attempts' => 10, 'next_retry_at' => null,
    ]);
    agePruneRow($row, Carbon::now()->subDays(40));
    $redelivered = false;
    DB::beforeExecuting(function (string $sql) use ($row, $finished, &$redelivered): void {
        if (!$redelivered && str_starts_with($sql, 'delete from') && str_contains($sql, $row->getTable())) {
            $redelivered = true;
            app(RedeliverWebhookAction::class)->execute($row);
            if ($finished) {
                WebhookTestHelpers::deliver($row);
            }
        }
    });

    // Zero retention proves the status guard; a fresh terminal row proves the age guard.
    $threshold = $finished ? Carbon::now()->subDays(30) : Carbon::now();
    $deleted = app(WebhookDeliveryStoreRepository::class)->pruneTerminalBefore($threshold);

    expect($redelivered)->toBeTrue()
        ->and($deleted)->toBe(0)
        ->and(WebhookDelivery::query()->whereKey($row->getKey())->value('status'))->toBe($finished ? 'delivered' : 'pending');
})->with([
    'delivered re-armed at zero retention' => ['delivered', false],
    'dead re-armed at zero retention' => ['dead', false],
    'delivered again inside retention' => ['delivered', true],
    'dead then delivered inside retention' => ['dead', true],
]);
