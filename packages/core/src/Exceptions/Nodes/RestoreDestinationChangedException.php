<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class RestoreDestinationChangedException extends VaulterException implements ProblemException
{
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
