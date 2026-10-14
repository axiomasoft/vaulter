<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

final class FirstStep implements Step
{
    public function name(): string
    {
        return 'first';
    }
}
