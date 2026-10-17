<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

use Vaulter\Contracts\Drive\DownloadGuard;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/** A module that vetoes downloads, as a quarantine package would: two guards, ahead of the built-in one, the second may throw. */
final class DownloadGuardModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('download-guards');
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
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->slot(DownloadGuard::class, FirstDownloadGuard::class, 'fixture.guard.first', priority: -20)
            ->slot(DownloadGuard::class, SecondDownloadGuard::class, 'fixture.guard.second', priority: -10);
    }
}
