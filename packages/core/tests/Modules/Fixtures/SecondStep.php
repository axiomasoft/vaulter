<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

final class SecondStep implements Step
{
    public function name(): string
    {
        return 'second';
    }
}
