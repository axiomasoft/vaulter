<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Command\Command;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;

/**
 * vaulter:webhooks:legacy-fanout lists the rows the pre-1.0 relay settled without
 * any delivery — their fan-out cannot be proven — and with --replay returns them to
 * the relay, which fans them out once per endpoint. Rows with deliveries and rows the
 * current relay completed are never listed.
 */
beforeEach(function (): void {
    Queue::fake();
    Carbon::setTestNow('2026-06-30 12:00:00');
});

function legacyRow(string $eventId, Carbon $createdAt, array $attributes = []): Outbox
{
    $row = WebhookTestHelpers::outbox([
        'event_id' => $eventId,
        'status' => 'completed',
        'legacy_fanout' => true,
        'fanned_out_at' => $createdAt,
        'available_at' => $createdAt,
        ...$attributes,
    ]);
    $row->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    return $row;
}

/** @return array{0: int, 1: string} */
function runLegacyFanout(array $arguments = []): array
{
    $exit = Artisan::call('vaulter:webhooks:legacy-fanout', $arguments);

    return [$exit, Artisan::output()];
}

it('lists only legacy rows without deliveries and changes nothing', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $ambiguous = legacyRow('legacy-lost', Carbon::now()->subDays(3));
    legacyRow('legacy-delivered', Carbon::now()->subDays(3));
    WebhookTestHelpers::delivery($endpoint, ['event_id' => 'legacy-delivered']);
    WebhookTestHelpers::outbox(['event_id' => 'current-empty', 'status' => 'completed', 'fanned_out_at' => now()]);

    [$exit, $output] = runLegacyFanout();

    expect($exit)->toBe(Command::SUCCESS)
        ->and($output)->toContain('legacy-lost')
        ->and($output)->not->toContain('legacy-delivered')
        ->and($output)->not->toContain('current-empty')
        ->and($output)->toContain('1 legacy outbox row without a provable fan-out')
        ->and($ambiguous->refresh()->status)->toBe('completed');
});

it('filters by --since and --until', function (): void {
    legacyRow('evt-jan', Carbon::parse('2026-01-01 00:00:00'));
    legacyRow('evt-mar', Carbon::parse('2026-03-01 00:00:00'));
    legacyRow('evt-may', Carbon::parse('2026-05-01 00:00:00'));

    [, $output] = runLegacyFanout(['--since' => '2026-02-01', '--until' => '2026-04-01']);

    expect($output)->toContain('evt-mar')->not->toContain('evt-jan')->not->toContain('evt-may');

    [$exit, $output] = runLegacyFanout(['--since' => 'not a date']);
    expect($exit)->toBe(Command::FAILURE)->and($output)->toContain('must be dates');
});

it('reports a dry-run replay without changing anything', function (): void {
    $row = legacyRow('legacy-lost', Carbon::now()->subDays(3));

    [, $output] = runLegacyFanout(['--replay' => true, '--dry-run' => true]);

    expect($output)->toContain('Would replay 1 legacy outbox row')
        ->and($row->refresh()->status)->toBe('completed')
        ->and($row->legacy_fanout)->toBeTrue();
});

it('replays a legacy row once per endpoint, skipping endpoints that already have it', function (): void {
    $createdAt = Carbon::now()->subDays(3);
    $this->travelTo($createdAt->copy()->subDay());
    $first = WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    $second = WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook']);
    $this->travelBack();
    Carbon::setTestNow('2026-06-30 12:00:00');
    WebhookTestHelpers::endpoint(['url' => 'https://late.test/hook']);
    $row = legacyRow('legacy-lost', $createdAt);

    [, $output] = runLegacyFanout(['--replay' => true]);
    expect($output)->toContain('Replayed 1 of 1 legacy outbox row');

    $row->refresh();
    expect($row->status)->toBe('pending')
        ->and($row->legacy_fanout)->toBeFalse()
        ->and($row->fanned_out_at)->toBeNull()
        ->and($row->fanout_cursor)->toBeNull();

    // Meanwhile the first endpoint got the event some other way.
    WebhookTestHelpers::delivery($first, ['event_id' => 'legacy-lost']);

    app(OutboxRelay::class)->process();

    expect($row->refresh()->status)->toBe('completed')
        ->and($row->delivery_count)->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', 'legacy-lost')->where('endpoint_id', $first->getKey())->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', 'legacy-lost')->where('endpoint_id', $second->getKey())->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', 'legacy-lost')->count())->toBe(2);

    // A second replay finds nothing left to replay.
    [, $output] = runLegacyFanout(['--replay' => true]);
    expect($output)->toContain('Replayed 0 of 0 legacy outbox rows');
});
