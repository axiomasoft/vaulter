<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures;

/** A slot contract of the fixture graph. */
interface Step
{
    public function name(): string;
}
