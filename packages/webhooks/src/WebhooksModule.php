<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Console\PruneWebhooksCommand;
use Vaulter\Webhooks\Console\RedeliverWebhooksCommand;
use Vaulter\Webhooks\Console\RetryWebhooksCommand;
use Vaulter\Webhooks\Doctor\Checks\WebhooksDeliveriesCheck;
use Vaulter\Webhooks\Errors\WebhooksProblemDefinitions;
use Vaulter\Webhooks\Schema\WebhooksSchema;
use Vaulter\Webhooks\Schema\WebhooksSchemaSets;
use Vaulter\Webhooks\Security\BlockedRangesInvariant;
use Vaulter\Webhooks\Security\SchemesInvariant;

/** Native code plugin for the webhooks package. */
#[Api(since: '1.0')]
final class WebhooksModule implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesDiagnostics;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.webhooks', version: '1.0.0', requires: ['vaulter.core', 'vaulter.drive'], resourceRoot: dirname(__DIR__).'/resources');
    }

    public function register(PluginRegistrar $registrar): void {}

    public function configRoot(): string
    {
        return 'vaulter-webhooks';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-webhooks.php';
    }

    public function optionsClass(): string
    {
        return WebhooksOptions::class;
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(plugin: self::class, activation: Activation::OptIn,
            routes: [dirname(__DIR__).'/routes/webhooks.php'], translations: ['vaulter-webhooks' => dirname(__DIR__).'/resources/lang'], commands: [RetryWebhooksCommand::class,
                PruneWebhooksCommand::class,
                RedeliverWebhooksCommand::class], schedules: [WebhooksSchedule::class], bindings: [WebhooksBindings::class],
            problems: [WebhooksProblemCodes::class],
            operations: [WebhooksOperationPolicies::class],
        );
    }

    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    public function tableNouns(): array
    {
        return ['webhook_endpoints', 'webhook_deliveries'];
    }

    public function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    /** @return list<class-string> */
    public function schemaSetProviders(): array
    {
        return [WebhooksSchemaSets::class];
    }

    /** @return list<class-string> */
    public function schemaComponents(): array
    {
        return [WebhooksSchema::class];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    public function slotContributions(): array
    {
        return [
            ['slot' => OutboxConsumer::class, 'id' => WebhookFanoutConsumer::ID, 'implementation' => WebhookFanoutConsumer::class],
            ['slot' => DoctorCheck::class, 'id' => 'core.webhooks.deliveries', 'implementation' => WebhooksDeliveriesCheck::class, 'key' => 'core.webhooks.deliveries'],
            ['slot' => ProductionInvariant::class, 'id' => 'webhooks.schemes', 'implementation' => SchemesInvariant::class, 'key' => 'webhooks.schemes'],
            ['slot' => ProductionInvariant::class, 'id' => 'webhooks.blocked-ranges', 'implementation' => BlockedRangesInvariant::class, 'key' => 'webhooks.blocked-ranges'],
        ];
    }

    /** @return list<class-string> */
    public function healthChecks(): array
    {
        return [WebhooksDeliveriesCheck::class];
    }

    /** @return list<class-string> */
    public function problemProviders(): array
    {
        return [WebhooksProblemDefinitions::class];
    }
}
