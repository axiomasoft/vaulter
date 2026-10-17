<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Vaulter\Testing\Concerns\EnablesModules;

/**
 * For a test file that asserts webhook outbox rows, endpoints or commands: boots the package with the
 * opt-in webhooks module switched on (D22). Until the outbox is written for every event (D29, P3.14)
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
