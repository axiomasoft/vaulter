<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Contracts;

interface ProblemException
{
    public function problemCode(): string;

    public function httpStatus(): int;
}
