<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\State;

use Vaulter\Documents\State\PendingStateDrainer;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * A module providing the documents' PendingStateDrainer capability the way the collaboration module will, from config:
 * a test sets {@see self::CONFIG_KEY} to a drainer class, and without it no drainer is available.
 */
final class PendingDrainerModule implements VaulterModule
{
    public const string CONFIG_KEY = 'vaulter-documents-testing.pending_drainer';

    public function id(): ModuleId
    {
        return new ModuleId('pending-drainer-fixture');
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
        return ['core' => '^1.0', 'drive' => '^1.0', 'documents' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module->providesFromConfig(PendingStateDrainer::class, self::CONFIG_KEY);
    }
}
