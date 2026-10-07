<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Content;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class ContentQuarantinedException extends VaulterException implements ProblemException
{
    public function __construct()
    {
        parent::__construct('[Vaulter] The content is quarantined or not yet available.');
    }

    public function problemCode(): string
    {
        return 'content_unavailable';
    }

    public function httpStatus(): int
    {
        return 423;
    }
}
