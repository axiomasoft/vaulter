<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\State;

use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Fixtures\State\PendingDrainerProvider;

/**
 * The documents suite with a module that may provide the PendingStateDrainer capability (config-selected, none by
 * default). Its tests migrate per test instead of wrapping each in a transaction (Pest.php): the contract and the
 * guards tell "inside a transaction" from "outside one", which a wrapping transaction would hide.
 */
abstract class StateTestCase extends DocumentTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PendingDrainerProvider::class];
    }
}
