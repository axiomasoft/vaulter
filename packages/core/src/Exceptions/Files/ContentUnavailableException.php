<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Files;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class ContentUnavailableException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The selected content is not available.');
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
