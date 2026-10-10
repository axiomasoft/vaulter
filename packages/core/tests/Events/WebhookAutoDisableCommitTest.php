<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\Events\WebhookEndpointDisabled;

uses(TestCase::class, DatabaseMigrations::class);

it('never announces an auto-disable whose commit failed, even after a later commit', function (string $error): void {
    config()->set('vaulter.webhooks.disable_after_days', 5);
    config()->set('vaulter.webhooks.retry_claim_seconds', 60);
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = WebhookTestHelpers::endpoint(['failing_since' => now()->subDays(5)->subSecond()]);
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $database = app(VaulterDatabase::class);
    $heard = [];
    Event::listen(WebhookEndpointDisabled::class, static function (WebhookEndpointDisabled $event) use ($endpoint, $database, &$heard): void {
        $heard[] = ['active' => $endpoint->fresh()->is_active, 'level' => $database->connection()->transactionLevel()];
    });
    $failed = false;
    Event::listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($error, &$failed): void {
        if (!$failed) {
            $failed = true;
            // Simulate a commit that aborts in the driver: Laravel's commit-error path
            // decrements the transaction level without clearing its after-commit queue.
            $event->connection->getPdo()->rollBack();

            throw new PDOException($error);
        }
    });

    expect(fn () => app()->call([new DeliverWebhook(deliveryId: (string) $delivery->getKey()), 'handle']))
        ->toThrow(PDOException::class, $error);
    expect($failed)->toBeTrue()
        ->and($database->connection()->transactionLevel())->toBe(0)
        ->and($endpoint->fresh()->is_active)->toBeTrue()
        ->and($delivery->fresh()->status)->toBe('pending')
        ->and($heard)->toBe([]);

    // A later, unrelated successful commit must not flush the abandoned notice.
    $database->transaction(static fn () => null);
    expect($heard)->toBe([]);

    // Once the delivery lease expires, a real disabling commit announces it once.
    $this->travel(61)->seconds();
    app()->call([new DeliverWebhook(deliveryId: (string) $delivery->getKey()), 'handle']);
    expect($endpoint->fresh()->is_active)->toBeFalse()
        ->and($delivery->fresh()->status)->toBe('dead')
        ->and($heard)->toBe([['active' => false, 'level' => 0]]);
})->with([
    'non-retryable commit failure' => ['commit failed'],
    'exhausted concurrency failure' => ['database is locked'],
]);
