<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Contracts;

interface ProblemException
{
    public function problemCode(): string;

    public function httpStatus(): int;

    /**
     * The machine-readable fields of the problem body. An `errors` key is a Laravel-style
     * field map and travels as the body's `errors`; every other key becomes `meta`, which
     * never carries storage paths, tenant ids, tokens or SQL.
     *
     * @return array<string, mixed>
     */
    public function problemDetails(): array;
}
