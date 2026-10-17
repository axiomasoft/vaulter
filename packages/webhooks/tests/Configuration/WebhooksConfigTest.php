<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;

/**
 * The `vaulter-webhooks.*` configuration (moved from the kernel ConfigValidator suite with the module, P4.13):
 * the defaults the package ships, the values it refuses at boot and the removed keys it names a replacement for.
 */
function webhooksRepository(): Repository
{
    return new Repository(['vaulter-webhooks' => require dirname(__DIR__, 2).'/config/vaulter-webhooks.php']);
}

it('validates the configuration defaults the package ships', function (): void {
    (new WebhooksConfig(webhooksRepository()))->validate();

    expect(true)->toBeTrue();
});

it('fails boot for a malformed webhook configuration', function (string $key, mixed $value): void {
    $repository = webhooksRepository();
    $repository->set($key, $value);

    (new WebhooksConfig($repository))->validate();
})->with([
    ['vaulter-webhooks.delivery.url_guard', stdClass::class],
    ['vaulter-webhooks.relay.fanout_chunk', 0],
    ['vaulter-webhooks.relay.fanout_chunk', 1001],
    ['vaulter-webhooks.relay.fanout_chunk', 'all'],
    ['vaulter-webhooks.retry_jitter', 0.9],
    ['vaulter-webhooks.retry_jitter', -0.1],
    ['vaulter-webhooks.retry_jitter', 'wide'],
    ['vaulter-webhooks.retry_schedule', [5, -1]],
    ['vaulter-webhooks.retry_schedule', [5, 'soon']],
    ['vaulter-webhooks.models.webhook_endpoint', stdClass::class],
    ['vaulter-webhooks.models.webhook_delivery', 42],
    ['vaulter-webhooks.schedule.webhooks_retry', 'sometimes'],
])->throws(InvalidConfigurationException::class);

it('refuses a removed pre-1.0 webhook delivery key and names its replacement', function (string $key, string $replacement): void {
    $repository = webhooksRepository();
    $repository->set('vaulter-webhooks.'.$key, 5);

    expect(fn () => (new WebhooksConfig($repository))->validate())
        ->toThrow(InvalidConfigurationException::class, "vaulter-webhooks.{$key} was removed: use {$replacement}");
})->with([
    ['max_attempts', 'vaulter-webhooks.retry_schedule'],
    ['backoff', 'vaulter-webhooks.retry_schedule'],
    ['disable_after_failures', 'vaulter-webhooks.disable_after_days'],
    ['signature_header', 'the Standard Webhooks headers'],
    ['timestamp_header', 'the Standard Webhooks headers'],
    ['event_header', 'the event type'],
]);

it('ships the 1.0 webhook delivery defaults', function (): void {
    $repository = webhooksRepository();
    $webhooks = new WebhooksConfig($repository);

    expect($webhooks->timeout())->toBe(15)
        ->and($webhooks->retrySchedule())->toBe([5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400])
        ->and($webhooks->retryJitter())->toBe(0.1)
        ->and($webhooks->disableAfterDays())->toBe(5)
        ->and($webhooks->secretRotationOverlap())->toBe(86400)
        ->and($webhooks->retentionDays())->toBe(30)
        ->and($webhooks->retryClaimSeconds())->toBe(60)
        ->and($webhooks->relayFanoutChunk())->toBe(100)
        ->and($webhooks->urlGuard())->toBe(DefaultOutboundUrlGuard::class)
        ->and($webhooks->allowedSchemes())->toBe(['http', 'https'])
        ->and($webhooks->maxResponseBytes())->toBe(65536)
        ->and($repository->has('vaulter-webhooks.max_attempts'))->toBeFalse();
});

it('caps the secret rotation overlap at seven days', function (): void {
    $repository = new Repository(['vaulter-webhooks' => ['secret_rotation_overlap' => 9_999_999]]);

    expect((new WebhooksConfig($repository))->secretRotationOverlap())->toBe(604800);
});

it('resolves the model keys from vaulter-webhooks.models and refuses a class that does not extend the shipped model', function (): void {
    $repository = new Repository(['vaulter-webhooks' => ['models' => ['webhook_endpoint' => stdClass::class]]]);
    $config = new WebhooksConfig($repository);

    expect(fn () => $config->model('webhook_endpoint'))->toThrow(InvalidConfigurationException::class, 'vaulter-webhooks.models.webhook_endpoint')
        ->and($config->model('webhook_delivery'))->toBe(WebhookDelivery::class)
        ->and(fn () => $config->model('nope'))->toThrow(InvalidConfigurationException::class, 'Unknown webhooks model key');

    $repository->set('vaulter-webhooks.models.webhook_endpoint', WebhookEndpoint::class);

    expect($config->model('webhook_endpoint'))->toBe(WebhookEndpoint::class);
});

it('keeps no webhook key under the kernel root: vaulter.webhooks is not read', function (): void {
    $repository = new Repository([
        'vaulter' => ['webhooks' => ['timeout' => 99, 'retry_jitter' => 0.4]],
        'vaulter-webhooks' => ['timeout' => 7],
    ]);
    $config = new WebhooksConfig($repository);

    expect($config->timeout())->toBe(7)
        ->and($config->retryJitter())->toBe(0.1);
});
