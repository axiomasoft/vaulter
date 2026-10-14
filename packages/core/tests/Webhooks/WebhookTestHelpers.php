<?php

declare(strict_types=1);

namespace Vaulter\Tests\Webhooks;

use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventRecorder;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Kernel;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Row builders for the M3b reliability tests (§2.11): stage outbox rows, register
 * endpoints, and seed deliveries directly so the tests focus on the relay / delivery
 * algorithm, not on row plumbing.
 */
final class WebhookTestHelpers
{
    /** whsec_ + base64 of 32 bytes (0x01..0x20): any valid secret works, this one is easy to recognise. */
    public const string SECRET = 'whsec_AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=';

    /** Switches the opt-in webhooks module on before the application boots (a TestCase's defineEnvironment). */
    public static function enableModule(Application $app): void
    {
        $app['config']->set('vaulter.modules.webhooks', true);
    }

    /**
     * Reseals the kernel of a booted application with the webhooks module switched `$active`, like a process
     * that read another `vaulter.modules` without a restart: the runtime gates (event recorder, commands, doctor
     * slot) follow the new state; routes, bindings and schedules applied at boot stay. A test seam only.
     */
    public static function switchModule(Application $app, bool $active): void
    {
        $overrides = (array) $app['config']->get('vaulter.modules', []);
        $overrides['webhooks'] = $active;
        $app['config']->set('vaulter.modules', $overrides);

        $kernel = new Kernel($app);
        $kernel->seal((new ModuleCompiler)->compileOffered(ModuleCatalog::of($app)->offered(), $overrides));
        $app->instance(Kernel::class, $kernel);
        $app->forgetInstance(EventRecorder::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function outbox(array $attributes = []): Outbox
    {
        // forceFill (P1.4/F-P1.8-02): Outbox is fully guarded — this trusted
        // fixture builder bypasses it exactly like OutboxRecorder::stage() does.
        $outbox = new Outbox;
        $outbox->forceFill(array_merge([
            'event_id' => 'evt-'.Str::uuid7()->toString(),
            'profile' => 'default',
            'topic' => 'node-1',
            'event' => 'folder.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'available_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
        ], $attributes));
        $outbox->save();

        return $outbox;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function endpoint(array $attributes = []): WebhookEndpoint
    {
        // forceFill (P1.4/F-P1.7-04): signing_secret/failure_count are excluded
        // from WebhookEndpoint's $fillable — bypassed here exactly like
        // WebhookEndpointStoreRepository::create() does.
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill(array_merge([
            'url' => 'https://hooks.test/receive',
            'events' => ['folder.created'],
            'signing_secret' => self::SECRET,
            'is_active' => true,
            'failure_count' => 0,
        ], $attributes));
        $endpoint->save();

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function delivery(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
    {
        // forceFill (P1.4/F-P1.7-04): WebhookDelivery is fully guarded —
        // bypassed here exactly like OutboxRelay::createDelivery() does.
        $delivery = new WebhookDelivery;
        $delivery->forceFill(array_merge([
            'endpoint_id' => $endpoint->getKey(),
            'event' => 'folder.created',
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'status' => 'pending',
            'attempts' => 0,
            'next_retry_at' => now(),
        ], $attributes));
        $delivery->save();

        return $delivery;
    }

    /** Run the delivery job once, as a queue worker would. */
    public static function deliver(WebhookDelivery $delivery): void
    {
        (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
            ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), vaulterConfig: app(VaulterConfig::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
    }
}
