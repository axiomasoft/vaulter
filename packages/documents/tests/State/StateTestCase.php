<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\State;

use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Fixtures\State\FakePendingStateDrainer;
use Vaulter\Documents\Tests\Fixtures\State\PendingDrainerModule;
use Vaulter\Documents\Tests\Fixtures\State\PendingDrainerProvider;

/**
 * The documents suite with a module that may provide the PendingStateDrainer capability (config-selected, none by
 * default). Its tests migrate per test instead of wrapping each in a transaction (Pest.php): the contract and the
 * guards tell "inside a transaction" from "outside one", which a wrapping transaction would hide.
 */
abstract class StateTestCase extends DocumentTestCase
{
    /** The handler graph is sealed at boot; absence fixtures must be selected before compilation. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $name = $this->nameWithDataSet();
        $absent = str_contains($name, 'without_resolving_a_drainer') || str_contains($name, 'no drainer');
        $app['config']->set(PendingDrainerModule::CONFIG_KEY,
            $absent ? null : FakePendingStateDrainer::class);
    }

    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PendingDrainerProvider::class];
    }
}
