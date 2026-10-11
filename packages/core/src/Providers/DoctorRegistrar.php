<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Doctor\Checks\AccessDriverCheck;
use Vaulter\Doctor\Checks\CacheCheck;
use Vaulter\Doctor\Checks\ConfigValidCheck;
use Vaulter\Doctor\Checks\DatabaseConnectionCheck;
use Vaulter\Doctor\Checks\DeprecatedKeysCheck;
use Vaulter\Doctor\Checks\QueueCheck;
use Vaulter\Doctor\Checks\RoutesAuthCheck;
use Vaulter\Doctor\Checks\SchedulerCheck;
use Vaulter\Doctor\Checks\SchemaIntegrityCheck;
use Vaulter\Doctor\Checks\SchemaKeyTypesCheck;
use Vaulter\Doctor\Checks\SchemaTablesCheck;
use Vaulter\Doctor\Checks\TenancyCheck;
use Vaulter\Doctor\Checks\WebhooksDeliveriesCheck;
use Vaulter\Doctor\Checks\WebhooksOutboxLagCheck;
use Vaulter\Doctor\Checks\WebhooksRelayLeasesCheck;
use Vaulter\Doctor\DoctorRegistry;

/** Tags core's doctor checks; every other package tags its own from its provider. */
final class DoctorRegistrar
{
    public function register(Application $app): void
    {
        $app->tag([
            DatabaseConnectionCheck::class,
            SchemaTablesCheck::class,
            SchemaKeyTypesCheck::class,
            SchemaIntegrityCheck::class,
            ConfigValidCheck::class,
            DeprecatedKeysCheck::class,
            RoutesAuthCheck::class,
            AccessDriverCheck::class,
            TenancyCheck::class,
            QueueCheck::class,
            SchedulerCheck::class,
            CacheCheck::class,
            WebhooksOutboxLagCheck::class,
            WebhooksRelayLeasesCheck::class,
            WebhooksDeliveriesCheck::class,
        ], DoctorRegistry::TAG);
    }
}
