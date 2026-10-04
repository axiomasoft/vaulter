<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookRetryDispatcher;

/**
 * Re-dispatch has a SINGLE owner (§2.11 / F-P1.7-05): vaulter:relay-outbox and
 * vaulter:retry-webhooks both claim a due delivery through the same
 * WebhookRetryDispatcher, which locks + re-arms next_retry_at before dispatch. Running
 * both back-to-back therefore fans a delivery out exactly once — RED on the old code
 * where each command independently dispatched the same due row.
 */
it('does not re-dispatch the same due delivery twice across the two commands, F-P1.7-05', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, [
        'status' => 'failed',
        'next_retry_at' => now()->subMinute(),
    ]);

    $this->artisan('vaulter:relay-outbox')->assertSuccessful();
    $this->artisan('vaulter:retry-webhooks')->assertSuccessful();

    Bus::assertDispatchedTimes(DeliverWebhook::class, 1);

    // The claim bumped next_retry_at forward, so the second sweep saw it as not-due.
    expect($delivery->refresh()->next_retry_at?->isFuture())->toBeTrue();
});

it('claims at most 1000 oldest due deliveries per tick and drains the rest without duplicate dispatch', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();
    $model = new WebhookDelivery;
    $table = $model->getConnection()->table($model->getTable());
    $dueAt = now()->subMinute();
    $rows = [];

    for ($index = 0; $index < 1003; $index++) {
        $rows[] = [
            'endpoint_id' => $endpoint->getKey(),
            'event' => 'folder.created',
            'payload' => json_encode(['event_id' => 'retry-'.$index], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'attempts' => 1,
            'next_retry_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ];
    }

    foreach (array_chunk($rows, 250) as $chunk) {
        $table->insert($chunk);
    }

    $orderedIds = $model->newQuery()->orderBy('next_retry_at')->orderBy($model->getQualifiedKeyName())->pluck($model->getKeyName())->all();
    $retry = app(WebhookRetryDispatcher::class);

    expect($retry->redispatchDue())->toBe(1000)
        ->and($model->newQuery()->dueForRetry()->count())->toBe(3);
    Bus::assertDispatchedTimes(DeliverWebhook::class, 1000);

    $claimedIds = $model->newQuery()->where('next_retry_at', '>', now())->orderBy($model->getQualifiedKeyName())->pluck($model->getKeyName())->all();
    expect($claimedIds)->toBe(array_slice($orderedIds, 0, 1000));

    expect($retry->redispatchDue())->toBe(3)
        ->and($retry->redispatchDue())->toBe(0)
        ->and($model->newQuery()->dueForRetry()->count())->toBe(0);
    Bus::assertDispatchedTimes(DeliverWebhook::class, 1003);
});

it('reclaims a lost delivery only after its claim window and never claims terminal rows', function (): void {
    Bus::fake();
    config()->set('vaulter.webhooks.retry_claim_seconds', 60);

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, [
        'status' => 'failed',
        'attempts' => 2,
        'next_retry_at' => now()->subMinute(),
    ]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'next_retry_at' => now()->subMinute()]);
    WebhookTestHelpers::delivery($endpoint, ['status' => 'delivered', 'next_retry_at' => now()->subMinute()]);

    $retry = app(WebhookRetryDispatcher::class);
    expect($retry->redispatchDue())->toBe(1)
        ->and($retry->redispatchDue())->toBe(0);
    Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(2);

    $this->travel(61)->seconds();

    expect($retry->redispatchDue())->toBe(1)
        ->and($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(2);
    Bus::assertDispatchedTimes(DeliverWebhook::class, 2);
});

it('rolls back the whole claim and dispatches no jobs when a row cannot be re-armed', function (): void {
    Bus::fake();

    $endpoint = WebhookTestHelpers::endpoint();
    $dueAt = now()->subMinute();
    $first = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'next_retry_at' => $dueAt]);
    $second = WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'next_retry_at' => $dueAt]);
    config()->set('vaulter.models.webhook_delivery', FailingClaimWebhookDelivery::class);
    FailingClaimWebhookDelivery::$saves = 0;

    expect(fn () => app(WebhookRetryDispatcher::class)->redispatchDue())
        ->toThrow(RuntimeException::class, 'claim failed');

    Bus::assertNotDispatched(DeliverWebhook::class);
    expect($first->refresh()->next_retry_at?->isPast())->toBeTrue()
        ->and($second->refresh()->next_retry_at?->isPast())->toBeTrue()
        ->and((new WebhookDelivery)->newQuery()->dueForRetry()->count())->toBe(2);
});

final class FailingClaimWebhookDelivery extends WebhookDelivery
{
    public static int $saves = 0;

    #[Override]
    public function save(array $options = []): bool
    {
        self::$saves++;

        if (self::$saves === 2) {
            throw new RuntimeException('claim failed');
        }

        return parent::save($options);
    }
}
