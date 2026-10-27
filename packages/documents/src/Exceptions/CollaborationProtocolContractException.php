<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class CollaborationProtocolContractException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] Collaboration adapter violated its session contract. Fix: correct the adapter.');
    }

    public function problemCode(): string
    {
        return 'collaboration_protocol_contract';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
