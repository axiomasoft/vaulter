<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Console\PruneWebhooksCommand;
use Vaulter\Console\RedeliverWebhooksCommand;
use Vaulter\Console\RelayWebhooksCommand;
use Vaulter\Console\RetryWebhooksCommand;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\Checks\WebhooksDeliveriesCheck;
use Vaulter\Doctor\Checks\WebhooksOutboxLagCheck;
use Vaulter\Doctor\Checks\WebhooksRelayLeasesCheck;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * The webhooks module (D22, D39): a logical manifest offered by the core provider until P4 moves the
 * webhooks code into its own package. Opt-in, because it opens outbound network traffic; it needs no
 * secret configuration, so activating it without any is not an error. It relays drive events, so it
 * requires the drive module.
 */
final class WebhooksModule implements VaulterModule
{
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
            ->routes(dirname(__DIR__, 2).'/routes/webhooks.php')
            ->problems(WebhooksProblemCodes::class)
            ->commands(
                RelayWebhooksCommand::class,
                RetryWebhooksCommand::class,
                PruneWebhooksCommand::class,
                RedeliverWebhooksCommand::class,
            )
            ->schedule(WebhooksSchedule::class)
            ->bindings(WebhooksBindings::class)
            ->slot(DoctorCheck::class, WebhooksOutboxLagCheck::class, 'core.webhooks.outbox_lag', key: 'core.webhooks.outbox_lag')
            ->slot(DoctorCheck::class, WebhooksRelayLeasesCheck::class, 'core.webhooks.relay_leases', key: 'core.webhooks.relay_leases')
            ->slot(DoctorCheck::class, WebhooksDeliveriesCheck::class, 'core.webhooks.deliveries', key: 'core.webhooks.deliveries');
    }
}
