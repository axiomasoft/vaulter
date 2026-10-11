<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Actions\Webhooks\RedeliverWebhookAction;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Repositories\Webhooks\OutboxStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * Prune deletes only finished work: an outbox row only once 'completed' (every
 * eligible endpoint has its delivery), a delivery only once delivered or dead. A
 * pending outbox row — including one whose fan-out stopped half way — and a
 * pending/failed delivery survive any age and any retention, even 0 days.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-30 12:00:00');
});

function agePruneRow(Outbox|WebhookDelivery $row, Carbon $at): void
{
    DB::table($row->getTable())->where('id', $row->getKey())->update(['created_at' => $at, 'updated_at' => $at]);
}

it('never prunes a pending outbox row, however old, even half fanned out', function (): void {
    $ancient = Carbon::now()->subYears(3);
    $untouched = WebhookTestHelpers::outbox(['available_at' => $ancient]);
    $halfway = WebhookTestHelpers::outbox([
        'available_at' => $ancient,
        'fanout_cursor' => '7',
        'delivery_count' => 2,
        'claim_token' => str_repeat('a', 32),
        'claim_generation' => 3,
        'claim_until' => $ancient,
        // A stray marker on a pending row must not make it look finished.
        'fanned_out_at' => $ancient,
    ]);
    agePruneRow($untouched, $ancient);
    agePruneRow($halfway, $ancient);
    $completed = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDay()]);

    Artisan::call('vaulter:webhooks:prune', ['--days' => '0', '--dry-run' => true]);
    expect(Artisan::output())->toContain('Would prune 1 completed outbox row');

    Artisan::call('vaulter:webhooks:prune', ['--days' => '0']);

    expect(Outbox::query()->whereKey($untouched->getKey())->exists())->toBeTrue()
        ->and(Outbox::query()->whereKey($halfway->getKey())->exists())->toBeTrue()
        ->and(Outbox::query()->whereKey($completed->getKey())->exists())->toBeFalse();
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

it('keeps a completed row inside the retention window', function (): void {
    config()->set('vaulter.webhooks.retention_days', 30);
    $recent = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(29)]);
    $old = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(30)]);

    Artisan::call('vaulter:webhooks:prune');

    expect(Outbox::query()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(Outbox::query()->whereKey($old->getKey())->exists())->toBeFalse();
});

it('keeps a completed outbox row that a replay turned pending between the select and the delete', function (): void {
    $row = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40), 'legacy_fanout' => true]);
    $replayed = false;
    DB::beforeExecuting(function (string $sql) use ($row, &$replayed): void {
        if (!$replayed && str_starts_with($sql, 'delete from')) {
            $replayed = true;
            DB::table($row->getTable())->where('id', $row->getKey())->update(['status' => 'pending', 'fanned_out_at' => null]);
        }
    });

    $deleted = app(OutboxStoreRepository::class)->pruneCompletedBefore(Carbon::now()->subDays(30));

    expect($replayed)->toBeTrue()
        ->and($deleted)->toBe(0)
        ->and(Outbox::query()->whereKey($row->getKey())->value('status'))->toBe('pending');
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
