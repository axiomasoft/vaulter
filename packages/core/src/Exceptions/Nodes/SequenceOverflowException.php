<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class SequenceOverflowException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The node sequence is exhausted.');
    }

    public function problemCode(): string
    {
        return 'node_sequence_exhausted';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
