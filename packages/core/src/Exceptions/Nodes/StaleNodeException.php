<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class StaleNodeException extends VaulterException implements ProblemException
{
    public function __construct()
    {
        parent::__construct('[Vaulter] The node changed; reload it before retrying.');
    }

    public function problemCode(): string
    {
        return 'node_sequence_mismatch';
    }

    public function httpStatus(): int
    {
        return 412;
    }
}
