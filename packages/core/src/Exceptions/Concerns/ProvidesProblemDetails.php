<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Concerns;

use Packman\Attributes\Api;
use Vaulter\Exceptions\Contracts\ProblemException;

/**
 * Default {@see ProblemException} behaviour for an
 * exception that has no fields of its own beyond the code and status.
 */
#[Api]
trait ProvidesProblemDetails
{
    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return [];
    }

    /**
     * Extra response headers the client needs to act on the error.
     *
     * @return array<string, string>
     */
    public function problemHeaders(): array
    {
        return [];
    }
}
