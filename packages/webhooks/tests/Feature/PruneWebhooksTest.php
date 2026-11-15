<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Repositories\WebhookDeliveryStoreRepository;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * vaulter:webhooks:prune: the retention sweep of the webhook deliveries reclaims TERMINAL rows past
 * its window. The outbox has its own retention, vaulter:outbox:prune. The contract pinned here:
 *
 *  - IN-FLIGHT IS SACRED. A 'pending'/'failed' delivery is still owned by the due-retry sweep and is
 *    NEVER pruned — not even at retention 0, not even with an artificially ancient timestamp. The guard
 *    is STATUS, not age.
 *  - TERMINAL IS RECLAIMED BY THE WINDOW. A 'delivered'/'dead' delivery keys off updated_at (its
 *    terminalisation time). The boundary is inclusive ('<='): a row exactly at the threshold is pruned,
 *    one strictly inside the window survives.
 *  - --dry-run reports the EXACT count a real run would remove and deletes nothing.
 *  - While the webhooks module is inactive the command no-ops with a notice and SUCCESS.
 *  - --days parses like vaulter:trash:purge: negative ⇒ 0, non-numeric ⇒ config default.
 *
 * Counts are asserted with the wrap-safe "{n} terminal" stem: the summary line is rendered through the
 * INFO component, which word-wraps it. Time is frozen so every threshold is exact.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-30 12:00:00');
    config()->set('vaulter-webhooks.retention_days', 30);
});

/**
 * Run the command and return its summary line as ONE unwrapped string. expectsOutputToContain
 * matches per rendered (word-wrapped) line, so a count phrase that straddles a wrap boundary
 * is unmatchable there; Artisan::output() hands back the whole, unwrapped line, so the exact
 * count + noun can be asserted deterministically regardless of terminal width.
 *
 * @param array<string, mixed> $arguments
 */
function runPrune(array $arguments = []): string
{
    $exit = Artisan::call('vaulter:webhooks:prune', $arguments);

    expect($exit)->toBe(Command::SUCCESS);

    return Artisan::output();
}

/** Force a delivery's terminalisation time (updated_at) — Eloquent overwrites it on save. */
function forceDeliveryTimestamps(WebhookDelivery $delivery, Carbon $updatedAt, ?Carbon $createdAt = null): void
{
    DB::table($delivery->getTable())
        ->where('id', $delivery->getKey())
        ->update([
            'updated_at' => $updatedAt,
            'created_at' => ($createdAt ?? $updatedAt),
        ]);
}

// ─────────────────── 1–4. IN-FLIGHT IS NEVER PRUNED (adversarial) ───────────────────

it('3) keeps a failed, due-for-retry delivery with an ancient updated_at at --days=0 (status guard excludes retryable)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    // 'failed' + a PAST next_retry_at ⇒ the due-retry sweep still owns it. Ancient
    // updated_at must not make the prune touch it: 'failed' is not terminal.
    $delivery = WebhookTestHelpers::delivery($endpoint, [
        'status' => 'failed',
        'attempts' => 2,
        'next_retry_at' => Carbon::now()->subHour(),
    ]);
    forceDeliveryTimestamps($delivery, updatedAt: Carbon::now()->subDays(100));

    $output = runPrune(['--days' => '0']);

    // Reports zero terminal deliveries — the retryable row is invisible to the prune.
    expect($output)->toContain('0 terminal')
        ->and(WebhookDelivery::query()->whereKey($delivery->getKey())->exists())->toBeTrue();
});

it('4) never deletes a pending delivery with an ancient updated_at', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, ['status' => 'pending']);
    forceDeliveryTimestamps($delivery, updatedAt: Carbon::now()->subDays(100));

    $output = runPrune(['--days' => '0']);

    expect($output)->toContain('0 terminal')
        ->and(WebhookDelivery::query()->whereKey($delivery->getKey())->exists())->toBeTrue();
});

// ─────────────── 5–6. SETTLED / TERMINAL deleted exactly by the window ───────────────

it('6) prunes delivered+dead deliveries past the window (keying off updated_at) and keeps terminal rows inside it', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // delivered, terminalised 40d ago — old updated_at but a RECENT created_at proves
    // the prune keys off updated_at, not created_at.
    $deliveredOld = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($deliveredOld, updatedAt: Carbon::now()->subDays(40), createdAt: Carbon::now()->subHour());

    // dead (DLQ), terminalised exactly at the threshold ⇒ pruned (inclusive).
    $deadAtBoundary = WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5]);
    forceDeliveryTimestamps($deadAtBoundary, updatedAt: Carbon::now()->subDays(30));

    // delivered, terminalised 29d ago (inside the window) ⇒ survives.
    $deliveredInside = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($deliveredInside, updatedAt: Carbon::now()->subDays(29));

    $output = runPrune();

    // Exactly the two past-window terminal rows are reported and gone; the one inside
    // the window (newer updated_at) survives.
    expect($output)->toContain('2 terminal')
        ->and(WebhookDelivery::query()->whereKey($deliveredOld->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($deadAtBoundary->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($deliveredInside->getKey())->exists())->toBeTrue();
});

// ─────────────── 7. --days=0 combined: prune settled/terminal, keep ALL in-flight ───────────────

it('7) at --days=0 prunes only terminal deliveries and leaves every in-flight (pending/failed) one intact', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // Terminal — reclaimable at retention 0 (timestamps at/under now).
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($delivered, updatedAt: Carbon::now()->subMinute());
    $dead = WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5]);
    forceDeliveryTimestamps($dead, updatedAt: Carbon::now()->subMinute());

    // In-flight — must survive regardless of age.
    $pendingDelivery = WebhookTestHelpers::delivery($endpoint, ['status' => 'pending']);
    forceDeliveryTimestamps($pendingDelivery, updatedAt: Carbon::now()->subDays(99));
    $failedDelivery = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => Carbon::now()->subMinute()]);
    forceDeliveryTimestamps($failedDelivery, updatedAt: Carbon::now()->subDays(99));

    expect(runPrune(['--days' => '0']))->toContain('2 terminal');

    expect(WebhookDelivery::query()->whereKey($delivered->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($dead->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($pendingDelivery->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->whereKey($failedDelivery->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->count())->toBe(2);
});

// ─────────────────────────── 8–9. --dry-run + count parity ───────────────────────────

it('8) --dry-run reports the would-prune count and deletes nothing', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($delivered, updatedAt: Carbon::now()->subDays(40));
    $deliveriesBefore = WebhookDelivery::query()->count();

    $output = runPrune(['--dry-run' => true]);

    expect($output)->toContain('Would prune 1 terminal')
        ->and(WebhookDelivery::query()->count())->toBe($deliveriesBefore);
});

it('9) the dry-run count equals the real deletion count over the same fixture (no drift)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    foreach (range(1, 4) as $i) {
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }

    // The same predicate drives both count and delete, so the numbers must agree.
    expect(app(WebhookDeliveryStoreRepository::class)->countTerminalBefore(threshold: Carbon::now()->subDays(30)))->toBe(4);

    $this->artisan('vaulter:webhooks:prune')->assertExitCode(Command::SUCCESS);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

// ─────────────────────────── 10–11. portable chunked delete ───────────────────────────

it('10) prunes more terminal rows than the chunk size across multiple rounds with no loss or double-count', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    foreach (range(1, 7) as $i) {
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }
    // A decoy that must NOT be counted/deleted: an in-flight row with an ancient timestamp.
    $failed = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => Carbon::now()->subHour()]);
    forceDeliveryTimestamps($failed, updatedAt: Carbon::now()->subDays(40));

    // chunk: 3 over 7 rows ⇒ rounds of 3 + 3 + 1; the returned count is the exact total.
    expect(app(WebhookDeliveryStoreRepository::class)->pruneTerminalBefore(threshold: Carbon::now()->subDays(30), chunk: 3))->toBe(7)
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('11) terminates on exactly chunk-sized terminal batches (full final batch boundary, no infinite loop)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    foreach (range(1, 3) as $i) {
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }

    expect(app(WebhookDeliveryStoreRepository::class)->pruneTerminalBefore(threshold: Carbon::now()->subDays(30), chunk: 3))->toBe(3)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

// ─────────────────────────── 12–13. gate / --days parsing ───────────────────────────

it('12) no-ops with a notice and SUCCESS when the webhooks module is off, touching nothing', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $terminal = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($terminal, updatedAt: Carbon::now()->subDays(99));

    WebhookTestHelpers::switchModule($this->app, false);

    $this->artisan('vaulter:webhooks:prune', ['--days' => '0'])
        ->expectsOutputToContain('Webhooks module is inactive')
        ->assertExitCode(Command::SUCCESS);

    expect(WebhookDelivery::query()->whereKey($terminal->getKey())->exists())->toBeTrue();
});

it('12b) does not error when the webhooks module is off and there is nothing seeded', function (): void {
    WebhookTestHelpers::switchModule($this->app, false);

    expect(runPrune())->toContain('nothing to prune');
});

it('13) treats --days=-5 as 0 — a delivery finished a minute ago IS pruned', function (): void {
    $row = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($row, updatedAt: Carbon::now()->subMinute());

    expect(runPrune(['--days' => '-5']))->toContain('retention 0 days')
        ->and(WebhookDelivery::query()->whereKey($row->getKey())->exists())->toBeFalse();
});

it('13b) falls back to the config default retention when --days is non-numeric', function (): void {
    config()->set('vaulter-webhooks.retention_days', 30);
    $endpoint = WebhookTestHelpers::endpoint();
    $old = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($old, updatedAt: Carbon::now()->subDays(40));
    $recent = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($recent, updatedAt: Carbon::now()->subDays(10));

    expect(runPrune(['--days' => 'abc']))->toContain('retention 30 days')
        ->and(WebhookDelivery::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

it('14) leaves the outbox to vaulter:outbox:prune', function (): void {
    $event = WebhookTestHelpers::outbox(['created_at' => Carbon::now()->subDays(400)], consumer: null);

    runPrune(['--days' => '0']);

    expect(OutboxEvent::query()->whereKey($event->getKey())->exists())->toBeTrue();
});
