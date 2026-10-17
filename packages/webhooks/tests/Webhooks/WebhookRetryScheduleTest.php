<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The 1.0 retry policy: a delay per failed attempt from webhooks.retry_schedule (with
 * jitter), the last failure after the schedule is spent is dead, and only a 2xx counts
 * as success — a redirect or a client error is a failed attempt like any other.
 */
beforeEach(function (): void {
    config()->set('vaulter-webhooks.retry_jitter', 0);
});

it('arms next_retry_at from the default schedule and dies on the tenth failed attempt', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $schedule = [5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];

    $this->freezeTime(function () use ($schedule): void {
        $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());

        foreach ($schedule as $index => $delay) {
            WebhookTestHelpers::deliver($delivery);
            $delivery->refresh();

            expect($delivery->status)->toBe('failed')
                ->and($delivery->attempts)->toBe($index + 1)
                ->and($delivery->next_retry_at->getTimestamp())->toBe(now()->addSeconds($delay)->getTimestamp());
        }

        WebhookTestHelpers::deliver($delivery);
        $delivery->refresh();

        expect($delivery->status)->toBe('dead')
            ->and($delivery->attempts)->toBe(10)
            ->and($delivery->next_retry_at)->toBeNull()
            ->and($delivery->claim_token)->toBeNull();
        Http::assertSentCount(10);

        // A dead delivery is terminal for the job: nothing more is sent.
        WebhookTestHelpers::deliver($delivery);
        Http::assertSentCount(10);
    });
});

it('spans about three days in total', function (): void {
    expect(array_sum(config('vaulter-webhooks.retry_schedule')))->toBeBetween(2.5 * 86400, 3.5 * 86400);
});

it('keeps jittered delays within the configured fraction', function (): void {
    config()->set('vaulter-webhooks.retry_jitter', 0.1);
    config()->set('vaulter-webhooks.retry_schedule', [1000]);
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);

    $seen = [];
    $this->freezeTime(function () use (&$seen): void {
        $endpoint = WebhookTestHelpers::endpoint();
        for ($run = 0; $run < 25; $run++) {
            $delivery = WebhookTestHelpers::delivery($endpoint);
            WebhookTestHelpers::deliver($delivery);
            $seen[] = $delivery->refresh()->next_retry_at->getTimestamp() - now()->getTimestamp();
        }
    });

    expect(min($seen))->toBeGreaterThanOrEqual(900)
        ->and(max($seen))->toBeLessThanOrEqual(1100)
        // 25 draws over a 200 s window do not all land on one value.
        ->and(count(array_unique($seen)))->toBeGreaterThan(1);
});

it('treats redirects and client errors as failures, never as delivered', function (int $status): void {
    Http::fake(['https://hooks.test/*' => Http::response('', $status, ['Location' => 'https://elsewhere.test/'])]);
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());

    WebhookTestHelpers::deliver($delivery);

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->last_error)->toBe('HTTP '.$status);
    Http::assertSentCount(1);
})->with([301, 302, 307, 400, 404, 410, 429]);

it('treats any 2xx as delivered', function (int $status): void {
    Http::fake(['https://hooks.test/*' => Http::response('', $status)]);
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());

    WebhookTestHelpers::deliver($delivery);

    expect($delivery->refresh()->status)->toBe('delivered');
})->with([200, 201, 202, 204]);

it('sends with the 15 second default timeout', function (): void {
    expect(config('vaulter-webhooks.timeout'))->toBe(15);
});
