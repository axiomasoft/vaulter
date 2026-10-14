<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

/**
 * The shape the scans share: a violation per distinct (view, file, symbol, kind, target), counted. `category` is a
 * hint for whoever seeds the allowlist; the comparison looks only at the key and the count.
 *
 * @phpstan-type Violation array{invariant: string, view: string, file: string, symbol: string, kind: string, target: string, target_owner: string, count: int, category?: string}
 */
final class Scan
{
    /** The reference kinds that name a type. */
    public const TYPE_KINDS = ['use', 'name', 'fqcn-string'];

    /**
     * @param  list<Violation> $violations one per occurrence
     * @return list<Violation> merged by key, counted, sorted
     */
    public static function merge(array $violations): array
    {
        $merged = [];
        foreach ($violations as $violation) {
            $key = implode('|', [$violation['view'], $violation['file'], $violation['symbol'], $violation['kind'], $violation['target']]);
            if (isset($merged[$key])) {
                $merged[$key]['count'] += $violation['count'];
            } else {
                $merged[$key] = $violation;
            }
        }
        ksort($merged, SORT_STRING);

        return array_values($merged);
    }

    /**
     * @param  array{file: string, symbol: string, kind: string, target: string} $reference
     * @return Violation
     */
    public static function violation(string $invariant, string $view, array $reference, string $targetOwner, ?string $category = null): array
    {
        $violation = [
            'invariant' => $invariant,
            'view' => $view,
            'file' => $reference['file'],
            'symbol' => $reference['symbol'],
            'kind' => $reference['kind'],
            'target' => $reference['target'],
            'target_owner' => $targetOwner,
            'count' => 1,
        ];
        if ($category !== null) {
            $violation['category'] = $category;
        }

        return $violation;
    }
}
