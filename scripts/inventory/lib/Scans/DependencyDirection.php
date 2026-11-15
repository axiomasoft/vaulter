<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

/**
 * A package refers only to its own types and to those of the packages in its `require`.
 *
 * `current`: the package of the file against its current Composer require. `target`: the owner of the code (type-map)
 * against the target graph; a type of a package that is only a transitive requirement is a `needs-explicit-require`
 * (the direct import wants a direct require), anything else a `reverse-edge`. Vendor namespaces are not scanned.
 *
 * @phpstan-import-type Violation from Scan
 */
final class DependencyDirection
{
    public const INVARIANT = 'I2';

    /** @return list<Violation> */
    public static function found(ScanContext $context): array
    {
        $found = [];
        foreach ($context->index->references() as $reference) {
            if (!in_array($reference['kind'], Scan::TYPE_KINDS, true)) {
                continue;
            }
            $package = $context->currentPackage($reference['file']);
            $current = $context->typePackage($reference['target']);
            if ($package !== null && $current !== null && $current !== $package && !in_array($current, $context->currentRequire($package), true)) {
                $found[] = Scan::violation(self::INVARIANT, 'current', $reference, $current);
            }

            $owner = $context->ownerAt($reference['file'], $reference['symbol']);
            $target = $context->typeOwners[ltrim($reference['target'], '\\')] ?? null;
            if ($owner !== null && $target !== null && $target !== $owner && !in_array($target, $context->targetRequire($owner), true)) {
                $category = in_array($target, $context->targetClosure($owner), true) ? 'needs-explicit-require' : 'reverse-edge';
                $found[] = Scan::violation(self::INVARIANT, 'target', $reference, $target, $category);
            }
        }

        return Scan::merge($found);
    }
}
