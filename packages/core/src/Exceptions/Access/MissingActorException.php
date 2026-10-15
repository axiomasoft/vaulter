<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Access;

use Vaulter\Attributes\Api;
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

    public function problemCode(): string
    {
        return 'actor_required';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
