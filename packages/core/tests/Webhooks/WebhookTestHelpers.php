<?php

declare(strict_types=1);

namespace Vaulter\Tests\Webhooks;

use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\DeliveryState;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Kernel;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Webhooks\WebhookFanoutConsumer;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Row builders for the reliability tests: write outbox events with their webhooks.fanout
 * delivery row (D29), register endpoints and seed webhook deliveries directly, so the
 * tests focus on the relay / delivery algorithm, not on row plumbing.
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
     * An outbox event (folder.created, external, tenant scope unless given) and, unless `$consumer` is null, the
     * pending delivery row of that consumer — what the outbox writer stores for a subscribed consumer.
     *
     * @param array<string, mixed> $attributes
     */
    public static function outbox(array $attributes = [], ?string $consumer = WebhookFanoutConsumer::ID): OutboxEvent
    {
        // forceFill: OutboxEvent is fully guarded — this trusted fixture builder bypasses it like OutboxWriter does.
        $outbox = new OutboxEvent;
        $outbox->forceFill(array_merge([
            'event_id' => 'evt-'.Str::uuid7()->toString(),
            'scope_kind' => 'tenant',
            'type' => 'folder.created',
            'external' => true,
            'payload' => ['node_id' => 'node-1', 'event_id' => 'evt-1'],
            'created_at' => now(),
        ], $attributes));
        $outbox->save();
        $outbox->refresh();

        if ($consumer !== null) {
            app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))->insert([
                'event_id' => $outbox->event_id,
                'consumer_id' => $consumer,
                'outbox_seq' => $outbox->outbox_seq,
                'state' => DeliveryState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $outbox->created_at,
                'lease_generation' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $outbox;
    }

    /**
     * The outbox delivery row of `$eventId` for `$consumer`, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function outboxDelivery(string $eventId, string $consumer = WebhookFanoutConsumer::ID): ?array
    {
        $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(OutboxStore::DELIVERIES))
            ->where('event_id', $eventId)->where('consumer_id', $consumer)->first();

        return $row === null ? null : (array) $row;
    }

    /** Makes the next INSERT into v_outbox fail, as a lost connection or a full disk would. */
    public static function failOutboxInsert(string $message = 'outbox insert failed'): void
    {
        $table = app(DatabaseConfig::class)->table(OutboxStore::EVENTS);
        app(VaulterDatabase::class)->connection()->beforeExecuting(static function (string $query) use ($table, $message): void {
            if (preg_match('/^insert into ["`]?'.preg_quote($table, '/').'["`]? /i', $query) === 1) {
                throw new \RuntimeException($message);
            }
        });
    }

    /** One relay pass, as vaulter:relay --once runs it; the deliveries finished by consumer id. */
    public static function relay(?int $limit = null): array
    {
        return app(OutboxRelay::class)->pass(limit: $limit);
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
        // bypassed here exactly like WebhookFanoutConsumer::createDeliveries() does.
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
