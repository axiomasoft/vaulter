<?php

declare(strict_types=1);

namespace Vaulter\Tests\Webhooks;

use Illuminate\Foundation\Application;
use Vaulter\Tests\TestCase;

/**
 * Boots the package with the opt-in webhooks module switched on (D22): the webhook suites exercise the
 * module, the global default (off) stays what a host gets.
 */
abstract class WebhooksTestCase extends TestCase
{
    /**
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        WebhookTestHelpers::enableModule($app);
    }
}
