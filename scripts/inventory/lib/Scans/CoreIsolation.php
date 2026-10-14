<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

/**
 * I1 — the core is clean: nothing in it refers to the types, tables, config keys or routes of another package.
 *
 * `current` reads packages/core/src as it is (config keys by their `vaulter-<package>.` root or defining file, routes
 * by defining file; tables are not counted: the core creates every table today). `target` reads the files the
 * type-map gives to the kernel wherever they live now, against the target owners: the coupling left after the split.
 *
 * @phpstan-import-type Violation from Scan
 */
final class CoreIsolation
{
    public const INVARIANT = 'I1';

    /** @return list<Violation> */
    public static function found(ScanContext $context): array
    {
        $found = [];
        foreach ($context->index->references() as $reference) {
            if (str_starts_with($reference['file'], 'packages/core/src/')) {
                $owner = self::current($context, $reference);
                if ($owner !== null) {
                    $found[] = Scan::violation(self::INVARIANT, 'current', $reference, $owner);
                }
            }
            if ($context->ownerAt($reference['file'], $reference['symbol']) === 'core') {
                $owner = self::target($context, $reference);
                if ($owner !== null) {
                    $found[] = Scan::violation(self::INVARIANT, 'target', $reference, $owner);
                }
            }
        }

        return Scan::merge($found);
    }

    /** @param array{file: string, symbol: string, kind: string, target: string} $reference */
    private static function current(ScanContext $context, array $reference): ?string
    {
        $foreign = match (true) {
            in_array($reference['kind'], Scan::TYPE_KINDS, true) => array_filter([$context->typePackage($reference['target'])]),
            $reference['kind'] === 'config' => $context->configOwners($reference['target'], 'current'),
            $reference['kind'] === 'route' => array_filter([$context->routeOwner($reference['target'], 'current')]),
            default => [],
        };

        return self::join($foreign);
    }

    /** @param array{file: string, symbol: string, kind: string, target: string} $reference */
    private static function target(ScanContext $context, array $reference): ?string
    {
        $foreign = match (true) {
            in_array($reference['kind'], Scan::TYPE_KINDS, true) => array_filter([$context->typeOwners[ltrim($reference['target'], '\\')] ?? null]),
            in_array($reference['kind'], ['table', 'table-literal'], true) => array_filter([$context->tableOwner($reference['target'])]),
            $reference['kind'] === 'config' => $context->configOwners($reference['target'], 'target'),
            $reference['kind'] === 'route' => array_filter([$context->routeOwner($reference['target'], 'target')]),
            default => [],
        };

        return self::join($foreign);
    }

    /** @param iterable<string> $owners */
    private static function join(iterable $owners): ?string
    {
        $foreign = [];
        foreach ($owners as $owner) {
            if ($owner !== 'core') {
                $foreign[$owner] = true;
            }
        }
        $foreign = array_keys($foreign);
        sort($foreign, SORT_STRING);

        return $foreign === [] ? null : implode(',', $foreign);
    }
}
