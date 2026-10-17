<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class RestoreDestinationChangedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The restore destination changed; retry with current permissions.');
    }

    public function problemCode(): string
    {
        return 'restore_destination_changed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
