<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/** Opt-in: contributes the second step only when `vaulter.modules.optional` is true. */
final class OptionalModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('optional');
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
        return ['host' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->slot(Step::class, SecondStep::class, 'optional.second', priority: 20, key: 'second')
            ->schema(__DIR__.'/migrations/optional');
    }
}
