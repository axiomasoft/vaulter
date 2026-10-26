<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\GuardedModule;

use Vaulter\Modules\Activation;
use Vaulter\Modules\GuardsRemoval;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/** A module that owns one table and guards its own removal ({@see GuardedRemovalGuard}): the subject of the uninstall guard tests. */
final class GuardedModule implements GuardsRemoval, VaulterModule
{
    public static function removalGuard(): string
    {
        return GuardedRemovalGuard::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('guarded');
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
        return ['core' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module->schema(__DIR__.'/migrations');
    }
}
