<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Repositories\Webhooks\OutboxStoreRepository;
use Vaulter\Repositories\Webhooks\WebhookDeliveryStoreRepository;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

uses(EnablesWebhooksModule::class);

/**
 * vaulter:webhooks:prune (master plan §2.11 / D14 / M3b): the reliability-channel
 * retention sweep reclaims SETTLED audit history past its window. The contract pinned
 * here, in priority order:
 *
 *  - IN-FLIGHT IS SACRED. A 'pending' outbox row and a 'pending'/'failed' delivery are
 *    still owned by the relay (drain / due-retry sweep) and are NEVER pruned — not even
 *    at retention 0, not even when their timestamps are artificially ancient. The guard
 *    is STATUS, not age.
 *  - SETTLED/TERMINAL IS RECLAIMED BY THE WINDOW. A 'completed' outbox row keys off
 *    fanned_out_at; a 'delivered'/'dead' delivery keys off updated_at (its
 *    terminalisation time). The boundary is inclusive ('<='): a row exactly at the
 *    threshold is pruned, one strictly inside the window survives.
 *  - --dry-run reports the EXACT counts a real run would remove and deletes nothing.
 *  - The whole surface is feature-gated: 'reliability' off ⇒ the command no-ops with a
 *    notice and SUCCESS, touching no table.
 *  - --days parses like vaulter:trash:purge: negative ⇒ 0, non-numeric ⇒ config default.
 *
 * The base Feature suite runs with 'reliability' ON (config default), so the v_outbox /
 * v_webhook_* tables exist. The chunking + boundary-precision cases call the store-repo
 * methods directly with a small chunk: arg (the command's portable batched delete) to
 * stay fast and deterministic. Time is frozen so every threshold is exact.
 *
 * NOTE on the delivery-count assertions. The command's summary line is rendered through
 * Laravel's INFO component, which word-wraps it — so an assertion may only match a
 * substring that does not cross a wrap boundary. The delivery counts are therefore
 * pinned with the short, wrap-safe "{n} terminal" stem (plus the exact DB row state),
 * NOT the full "{n} terminal deliveries older than ..." phrase. This stem also dodges a
 * grammar bug in the app output: the plural suffix is appended to the whole word
 * ('delivery' + 'ies'), so for n != 1 it renders "{n} terminal deliveryies" rather than
 * "deliveries". The count value itself is correct; only the noun is malformed. Reported
 * as a discrepancy — app code is left untouched. The outbox noun ('row' / 'rows') is
 * pluralised correctly, so those assertions use the full word.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-06-30 12:00:00');
    config()->set('vaulter.webhooks.retention_days', 30);
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

it('1) keeps a pending outbox row with NULL fanned_out_at and an ancient created_at at --days=0', function (): void {
    $row = WebhookTestHelpers::outbox([
        'status' => 'pending',
        'fanned_out_at' => null,
        'created_at' => Carbon::now()->subYears(5),
    ]);

    $this->artisan('vaulter:webhooks:prune', ['--days' => '0'])
        ->expectsOutputToContain('Pruned 0 completed outbox rows')
        ->assertExitCode(Command::SUCCESS);

    expect(Outbox::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('2) never deletes a pending outbox row even with an artificially old fanned_out_at (guard is status, not time)', function (): void {
    // Adversarial: a 'pending' row carrying a terminal-looking fanned_out_at far in the
    // past. The status guard must still exclude it — only 'completed' is reclaimable.
    $row = WebhookTestHelpers::outbox([
        'status' => 'pending',
        'fanned_out_at' => Carbon::now()->subDays(100),
        'created_at' => Carbon::now()->subDays(100),
    ]);

    $this->artisan('vaulter:webhooks:prune', ['--days' => '0'])
        ->expectsOutputToContain('Pruned 0 completed outbox rows')
        ->assertExitCode(Command::SUCCESS);

    expect(Outbox::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

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

it('5) prunes a completed outbox row at the threshold but keeps one inside the window (inclusive <=)', function (): void {
    // retention 30d ⇒ threshold = now - 30d. fanned_out_at == threshold ⇒ pruned;
    // fanned_out_at = now - 29d (newer, inside the window) ⇒ survives.
    $atBoundary = WebhookTestHelpers::outbox([
        'status' => 'completed',
        'fanned_out_at' => Carbon::now()->subDays(30),
        'created_at' => Carbon::now()->subDays(40),
    ]);
    $insideWindow = WebhookTestHelpers::outbox([
        'status' => 'completed',
        'fanned_out_at' => Carbon::now()->subDays(29),
        'created_at' => Carbon::now()->subDays(40),
    ]);

    $this->artisan('vaulter:webhooks:prune')
        ->expectsOutputToContain('Pruned 1 completed outbox row')
        ->assertExitCode(Command::SUCCESS);

    expect(Outbox::query()->whereKey($atBoundary->getKey())->exists())->toBeFalse()
        ->and(Outbox::query()->whereKey($insideWindow->getKey())->exists())->toBeTrue();
});

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

it('7) at --days=0 prunes only settled+terminal rows and leaves every in-flight (pending/failed) row intact', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // Settled/terminal — all reclaimable at retention 0 (timestamps at/under now).
    $completed = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subMinute()]);
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($delivered, updatedAt: Carbon::now()->subMinute());
    $dead = WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 5]);
    forceDeliveryTimestamps($dead, updatedAt: Carbon::now()->subMinute());

    // In-flight — must survive regardless of age.
    $pendingOutbox = WebhookTestHelpers::outbox(['status' => 'pending', 'fanned_out_at' => null, 'created_at' => Carbon::now()->subDays(99)]);
    $pendingDelivery = WebhookTestHelpers::delivery($endpoint, ['status' => 'pending']);
    forceDeliveryTimestamps($pendingDelivery, updatedAt: Carbon::now()->subDays(99));
    $failedDelivery = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => Carbon::now()->subMinute()]);
    forceDeliveryTimestamps($failedDelivery, updatedAt: Carbon::now()->subDays(99));

    $output = runPrune(['--days' => '0']);

    // Both counts reported on the one summary line: 1 settled outbox + 2 terminal.
    expect($output)->toContain('Pruned 1 completed outbox row')
        ->and($output)->toContain('2 terminal');

    // Exact survivors: only the three in-flight rows.
    expect(Outbox::query()->whereKey($completed->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($delivered->getKey())->exists())->toBeFalse()
        ->and(WebhookDelivery::query()->whereKey($dead->getKey())->exists())->toBeFalse()
        ->and(Outbox::query()->whereKey($pendingOutbox->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->whereKey($pendingDelivery->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->whereKey($failedDelivery->getKey())->exists())->toBeTrue()
        // And the live set is exactly the survivors, nothing stray.
        ->and(Outbox::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(2);
});

// ─────────────────────────── 8–9. --dry-run + count parity ───────────────────────────

it('8) --dry-run reports the would-prune counts and deletes nothing', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    $delivered = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($delivered, updatedAt: Carbon::now()->subDays(40));

    $outboxBefore = Outbox::query()->count();
    $deliveriesBefore = WebhookDelivery::query()->count();

    $output = runPrune(['--dry-run' => true]);

    // The dry-run wording ("Would prune") with both counts.
    expect($output)->toContain('Would prune 2 completed outbox rows')
        ->and($output)->toContain('1 terminal');

    // Row counts unchanged — a dry-run touches nothing.
    expect(Outbox::query()->count())->toBe($outboxBefore)
        ->and(WebhookDelivery::query()->count())->toBe($deliveriesBefore);
});

it('9) the dry-run count equals the real deletion count over the same fixture (no drift)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    foreach (range(1, 3) as $i) {
        WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    }
    foreach (range(1, 4) as $i) {
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }

    // The same predicate drives both count and delete, so the numbers must agree.
    $outbox = app(OutboxStoreRepository::class);
    $deliveries = app(WebhookDeliveryStoreRepository::class);
    $threshold = Carbon::now()->subDays(30);

    $outboxDryCount = $outbox->countCompletedBefore(threshold: $threshold);
    $deliveryDryCount = $deliveries->countTerminalBefore(threshold: $threshold);

    expect($outboxDryCount)->toBe(3)
        ->and($deliveryDryCount)->toBe(4);

    $this->artisan('vaulter:webhooks:prune')->assertExitCode(Command::SUCCESS);

    // Everything the dry-run promised is now gone; nothing of either kind remains.
    expect(Outbox::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

// ─────────────────────────── 10–11. portable chunked delete ───────────────────────────

it('10) prunes more settled rows than the chunk size across multiple rounds with no loss or double-count', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // 7 settled outbox rows + 7 terminal deliveries, all past a 30d threshold.
    foreach (range(1, 7) as $i) {
        WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }
    // Decoys that must NOT be counted/deleted: in-flight rows with ancient timestamps.
    WebhookTestHelpers::outbox(['status' => 'pending', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    $failed = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => Carbon::now()->subHour()]);
    forceDeliveryTimestamps($failed, updatedAt: Carbon::now()->subDays(40));

    $outbox = app(OutboxStoreRepository::class);
    $deliveries = app(WebhookDeliveryStoreRepository::class);
    $threshold = Carbon::now()->subDays(30);

    // chunk: 3 over 7 rows ⇒ rounds of 3 + 3 + 1; the returned count is the exact total.
    $prunedOutbox = $outbox->pruneCompletedBefore(threshold: $threshold, chunk: 3);
    $prunedDeliveries = $deliveries->pruneTerminalBefore(threshold: $threshold, chunk: 3);

    expect($prunedOutbox)->toBe(7)
        ->and($prunedDeliveries)->toBe(7)
        // Only the in-flight decoys remain.
        ->and(Outbox::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(1);
});

it('11) terminates on exactly chunk-sized settled batches (full final batch boundary, no infinite loop)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // Exactly 3 settled rows of each kind with chunk: 3 ⇒ the first round is FULL, the
    // second round returns 0 keys and the loop must stop (not spin forever).
    foreach (range(1, 3) as $i) {
        WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }

    $outbox = app(OutboxStoreRepository::class);
    $deliveries = app(WebhookDeliveryStoreRepository::class);
    $threshold = Carbon::now()->subDays(30);

    expect($outbox->pruneCompletedBefore(threshold: $threshold, chunk: 3))->toBe(3)
        ->and($deliveries->pruneTerminalBefore(threshold: $threshold, chunk: 3))->toBe(3)
        ->and(Outbox::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

// ─────────────────────────── 12–14. gate / --days parsing / dual ───────────────────────────

it('12) no-ops with a notice and SUCCESS when the reliability feature is off, touching nothing', function (): void {
    // Tables already exist (migrated with reliability ON); flip the runtime gate off.
    // The command must bail BEFORE querying, leaving every row intact.
    $endpoint = WebhookTestHelpers::endpoint();
    $settled = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(99)]);
    $terminal = WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'attempts' => 1]);
    forceDeliveryTimestamps($terminal, updatedAt: Carbon::now()->subDays(99));

    WebhookTestHelpers::switchModule($this->app, false);

    $this->artisan('vaulter:webhooks:prune', ['--days' => '0'])
        ->expectsOutputToContain('Webhooks module is inactive')
        ->assertExitCode(Command::SUCCESS);

    // Even a clearly-prunable settled/terminal pair is left untouched.
    expect(Outbox::query()->whereKey($settled->getKey())->exists())->toBeTrue()
        ->and(WebhookDelivery::query()->whereKey($terminal->getKey())->exists())->toBeTrue();
});

it('12b) does not error when reliability is off and there is nothing seeded', function (): void {
    WebhookTestHelpers::switchModule($this->app, false);

    expect(runPrune())->toContain('nothing to prune');
});

it('13) treats --days=-5 as 0 — a row settled a minute ago IS pruned', function (): void {
    // At retention 0 the threshold is now; a row completed a minute ago is past it.
    $row = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subMinute()]);

    // The summary echoes "retention 0 days", proving --days=-5 floored to 0.
    expect(runPrune(['--days' => '-5']))->toContain('retention 0 days')
        ->and(Outbox::query()->whereKey($row->getKey())->exists())->toBeFalse();
});

it('13b) falls back to the config default retention when --days is non-numeric', function (): void {
    config()->set('vaulter.webhooks.retention_days', 30);

    // With the 30d default: a row completed 40d ago is pruned; one 10d ago survives.
    $old = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    $recent = WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(10)]);

    // "retention 30 days" proves the non-numeric --days fell back to the config default.
    expect(runPrune(['--days' => 'abc']))->toContain('retention 30 days')
        ->and(Outbox::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(Outbox::query()->whereKey($recent->getKey())->exists())->toBeTrue();
});

it('14) one run prunes BOTH settled outbox and terminal deliveries, reporting the two counts separately', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    // 2 settled outbox + 3 terminal deliveries, all 40d old (past the 30d default).
    WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    WebhookTestHelpers::outbox(['status' => 'completed', 'fanned_out_at' => Carbon::now()->subDays(40)]);
    foreach (['delivered', 'dead', 'delivered'] as $status) {
        $d = WebhookTestHelpers::delivery($endpoint, ['status' => $status, 'attempts' => 3]);
        forceDeliveryTimestamps($d, updatedAt: Carbon::now()->subDays(40));
    }

    $output = runPrune();

    // One run, both counts on the summary line, each correct and separate.
    expect($output)->toContain('Pruned 2 completed outbox rows')
        ->and($output)->toContain('3 terminal')
        ->and(Outbox::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});
