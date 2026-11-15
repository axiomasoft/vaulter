<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Concerns;

use Vaulter\Testing\Concerns\EnablesModules;

/**
 * For a test file that asserts webhook outbox rows, endpoints or commands: boots the package with the
 * opt-in webhooks module switched on. Until the outbox is written for every event
 * the outbox row is a webhooks-module sink.
 */
trait EnablesWebhooksModule
{
    use EnablesModules;

    /** @return list<string> */
    protected function modulesToEnable(): array
    {
        return ['webhooks'];
    }
}
