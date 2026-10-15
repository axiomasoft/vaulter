<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class StorageNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'drive_not_found';

    private const int HTTP_STATUS = 404;

    public static function forId(int|string $id): self
    {
        return new self(
            "[Vaulter] Storage [{$id}] was not found. "
            .'Why: the resource does not exist or is not visible to the current actor. '
            .'Fix: use an accessible resource in the current drive and tenant. '
            .'Docs: '.self::docs('storage-not-found'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
