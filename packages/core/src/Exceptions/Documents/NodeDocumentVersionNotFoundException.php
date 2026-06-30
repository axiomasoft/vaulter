<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Documents;

use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document version lookup did not resolve a version belonging to the node (§2.5 /
 * M4a) — either it does not exist or it belongs to another node. "Not found" and "no
 * access to another node's version" are deliberately indistinguishable to the caller.
 */
final class NodeDocumentVersionNotFoundException extends VaulterException
{
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
}
