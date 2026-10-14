<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Support;

use Vaulter\Exceptions\VaulterException;

/**
 * A module's contribution to a slot returned something its slot does not allow (a reserved or duplicate
 * fragment key, a fragment of the wrong type). An integration error, never a silent skip.
 */
final class InvalidExtensionResult extends VaulterException
{
    public static function reservedKey(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Vaulter] %s contributes the reserved fragment key "%s". Why: the snapshot owns that key. Fix: contribute under an own key.',
            $slot,
            $key,
        ));
    }

    public static function duplicateKey(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Vaulter] %s is contributed twice under the fragment key "%s". Why: one fragment per key. Fix: deactivate one of the contributing modules.',
            $slot,
            $key,
        ));
    }

    public static function invalidFragment(string $slot, string $key, string $expected): self
    {
        return new self(sprintf(
            '[Vaulter] %s returned an invalid "%s" fragment. Why: a fragment must be %s keyed by a node id of the page. Fix: return only that.',
            $slot,
            $key,
            $expected,
        ));
    }
}
