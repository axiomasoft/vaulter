<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Vaulter\Filament\Providers\BindingsRegistrar;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * The Filament admin module (D22): its config and config reader. The panel plugin ({@see VaulterPlugin}) is
 * never registered by the manifest — the host adds it to its panel explicitly.
 */
final class FilamentModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('filament');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-filament', dirname(__DIR__).'/config/vaulter-filament.php')
            ->bindings(BindingsRegistrar::class);
    }
}
