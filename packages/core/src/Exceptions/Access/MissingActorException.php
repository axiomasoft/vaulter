<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Access;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class MissingActorException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function required(): self
    {
        return new self('[Vaulter] An actor is required. Why: no authenticated user or explicit system actor was supplied. Fix: authenticate a user, use actingAs(), or explicitly choose asSystem(reason) in trusted jobs and commands. Docs: '.self::docs('actor-required'));
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::ActorRequired, category: ErrorCategory::Authentication);
    }

    public function problemCode(): string
    {
        return 'actor_required';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
