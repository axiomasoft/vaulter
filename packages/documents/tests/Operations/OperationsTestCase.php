<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Operations;

use Illuminate\Foundation\Application;
use Vaulter\Documents\Tests\Document\DocumentTestCase;

/**
 * Boots documents with comments ON and the real database grant
 * resolver, so the Documents API runs every guard (ACL, profile, tenancy) and every
 * route of the HTTP parity checks is registered. Flags are set here, before boot,
 * because the routes are registered at boot.
 */
abstract class OperationsTestCase extends DocumentTestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vaulter-documents.comments.enabled', true);
        $app['config']->set('vaulter-drive.access.permission_driver', 'database');
    }
}
