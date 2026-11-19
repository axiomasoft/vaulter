<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\Apps;

use Illuminate\Support\ServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;

final class VetoDriveProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance(PluginActivationGate::class, new class implements PluginActivationGate
        {
            public function isEnabled(string $pluginId): bool
            {
                return $pluginId !== 'vaulter.drive';
            }
        });
    }
}
