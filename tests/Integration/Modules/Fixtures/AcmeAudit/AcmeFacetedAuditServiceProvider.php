<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeAudit;

use Illuminate\Support\ServiceProvider;
use Vaulter\Laravel\VaulterPluginRegistrar;

final class AcmeFacetedAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        VaulterPluginRegistrar::register($this->app, AcmeFacetedAuditPlugin::class);
    }
}
