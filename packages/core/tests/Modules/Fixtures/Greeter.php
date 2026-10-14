<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

/** A capability contract of the fixture graph. */
interface Greeter
{
    public function greet(): string;
}
