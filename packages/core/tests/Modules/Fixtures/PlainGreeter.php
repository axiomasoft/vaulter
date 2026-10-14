<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

final class PlainGreeter implements Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}
