<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class NotAFileException extends VaulterException implements ProblemException
{
    public function __construct()
    {
        parent::__construct('[Vaulter] This operation requires a file.');
    }

    public function problemCode(): string
    {
        return 'not_a_file';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
