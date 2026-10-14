<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

final class LoudGreeter implements Greeter
{
    public function greet(): string
    {
        return 'HELLO';
    }
}
