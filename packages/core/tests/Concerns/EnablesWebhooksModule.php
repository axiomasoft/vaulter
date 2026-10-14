<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Illuminate\Foundation\Application;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * For a test file that asserts webhook outbox rows, endpoints or commands: boots the package with the
 * opt-in webhooks module switched on (D22). Until the outbox is written for every event (D29, P3.14)
 * the outbox row is a webhooks-module sink.
 */
trait EnablesWebhooksModule
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        WebhookTestHelpers::enableModule($app);
    }
}
