<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Concerns;

use Vaulter\Testing\Concerns\EnablesModules;

/** For a test file that asserts webhook outbox rows: boots the package with the opt-in webhooks module switched on (D22). */
trait EnablesWebhooksModule
{
    use EnablesModules;

    /** @return list<string> */
    protected function modulesToEnable(): array
    {
        return ['webhooks'];
    }
}
