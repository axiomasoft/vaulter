<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document version lookup did not resolve a version belonging to the node (§2.5 /
 * M4a) — either it does not exist or it belongs to another node. "Not found" and "no
 * access to another node's version" are deliberately indistinguishable to the caller.
 */
final class DocumentRevisionNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'document_revision_not_found';

    private const int HTTP_STATUS = 404;

    public static function forNode(mixed $nodeId, mixed $versionId): self
    {
        return new self(
            sprintf(
                '[Vaulter] Document version [%s] was not found on node [%s]. ',
                Identity::stringify($versionId),
                Identity::stringify($nodeId),
            )
            .'Why: the version does not exist or belongs to a different node. '
            .'Fix: list the node history (GET .../versions) and restore an id from it. '
            .'Docs: '.self::docs('document-version-not-found'),
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
