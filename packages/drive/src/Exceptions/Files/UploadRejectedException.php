<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Files;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class UploadRejectedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(public readonly string $reason)
    {
        parent::__construct('[Vaulter] Upload rejected: '.$reason.'.');
    }

    public function problemCode(): string
    {
        return 'upload_rejected';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function problemDetails(): array
    {
        return ['reason' => $this->reason];
    }
}
