<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;
use Vaulter\Webhooks\Console\PruneWebhooksCommand;
use Vaulter\Webhooks\Console\RedeliverWebhooksCommand;
use Vaulter\Webhooks\Console\RetryWebhooksCommand;
use Vaulter\Webhooks\Doctor\Checks\WebhooksDeliveriesCheck;
use Vaulter\Webhooks\Schema\WebhooksSchema;
use Vaulter\Webhooks\Security\BlockedRangesInvariant;
use Vaulter\Webhooks\Security\SchemesInvariant;

/**
 * The webhooks module: the manifest of the vaulter-webhooks package, offered by its provider. Opt-in,
 * because it opens outbound network traffic; it needs no secret configuration, so activating it without
 * any is not an error. It relays drive events, so it requires the drive module. Its REST routes, commands,
 * schedule, doctor check, problem codes, tables and `vaulter-webhooks.*` configuration go with it.
 */
#[Internal]
final class WebhooksModule implements OwnsSchema, VaulterModule
{
    public static function ownedSchema(): string
    {
        return WebhooksSchema::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('webhooks');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::OptIn;
    }

    public function requires(): array
    {
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-webhooks', dirname(__DIR__).'/config/vaulter-webhooks.php')
            ->schema(dirname(__DIR__).'/database/migrations')
            ->routes(dirname(__DIR__).'/routes/webhooks.php')
            ->problems(WebhooksProblemCodes::class)
            ->operations(WebhooksOperationPolicies::class)
            ->commands(
                RetryWebhooksCommand::class,
                PruneWebhooksCommand::class,
                RedeliverWebhooksCommand::class,
            )
            ->schedule(WebhooksSchedule::class)
            ->bindings(WebhooksBindings::class)
            // Its outbox consumer turns external events into deliveries.
            ->slot(OutboxConsumer::class, WebhookFanoutConsumer::class, WebhookFanoutConsumer::ID)
            ->slot(DoctorCheck::class, WebhooksDeliveriesCheck::class, 'core.webhooks.deliveries', key: 'core.webhooks.deliveries')
            ->slot(ProductionInvariant::class, SchemesInvariant::class, 'webhooks.schemes', key: 'webhooks.schemes')
            ->slot(ProductionInvariant::class, BlockedRangesInvariant::class, 'webhooks.blocked-ranges', key: 'webhooks.blocked-ranges');
    }
}
