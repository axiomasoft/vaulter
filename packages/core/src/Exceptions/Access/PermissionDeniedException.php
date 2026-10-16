<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Access;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class PermissionDeniedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'permission_denied';

    private const int HTTP_STATUS = 403;

    public static function forAction(string $action): self
    {
        return new self(
            sprintf('[Vaulter] You are not authorized to [%s] this document. ', $action)
            .'Why: the configured PermissionResolver returned false. '
            .'Fix: grant the required access level or adjust your resolver. '
            .'Docs: '.self::docs('permission-denied'),
        );
    }

    /**
     * The Drive ACL denial: the AccessResolver vetoed the ability on the node
     * (no matching grant, or a deny overrode it).
     */
    public static function forAbility(string $ability): self
    {
        return new self(
            sprintf('[Vaulter] You are not authorized to [%s] this node. ', $ability)
            .'Why: the AccessResolver found no allowing grant, or a deny overrode it (deny-wins). '
            .'Fix: grant the subject the required level/scope on this node or an ancestor. '
            .'Docs: '.self::docs('permission-denied'),
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
