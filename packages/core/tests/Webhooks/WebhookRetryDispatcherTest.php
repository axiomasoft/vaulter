<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

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
