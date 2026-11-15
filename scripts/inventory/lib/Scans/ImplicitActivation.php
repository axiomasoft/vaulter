<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

/**
 * No implicit activation: every `class_exists`/`interface_exists`/… and `->bound(`/`->resolved(` probe in
 * packages/*\/src is a named allowlist entry with a category (activation, jwt-optional, explicit-adapter,
 * config-validation, host-lifecycle). The anchor is file + symbol + the probed expression, never a whole class.
 *
 * @phpstan-import-type Violation from Scan
 */
final class ImplicitActivation
{
    public const INVARIANT = 'I4';

    /** @return list<Violation> */
    public static function found(ScanContext $context): array
    {
        $found = [];
        foreach ($context->index->references('probe') as $reference) {
            if (preg_match('#^packages/[^/]+/src/#', $reference['file']) !== 1) {
                continue;
            }
            $found[] = Scan::violation(self::INVARIANT, 'current', $reference, $context->currentPackage($reference['file']) ?? '-');
        }

        return Scan::merge($found);
    }
}
