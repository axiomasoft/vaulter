<?php

declare(strict_types=1);

namespace Vaulter\Release\Contract;

/**
 * One classified change of a contract: `{surface, subject, rule, classification, before_hash, after_hash, explanation}`.
 * ADD only for a provably allowed simple case; REVIEW blocks like BREAK, it only does not claim the break is proven.
 *
 * @phpstan-type ChangeRow array{surface: string, subject: string, rule: string, classification: string, before_hash: string|null, after_hash: string|null, explanation: string}
 */
final class Change
{
    public const ADD = 'ADD';

    public const BREAK = 'BREAK';

    public const REVIEW = 'REVIEW';

    /** @return ChangeRow */
    public static function make(string $surface, string $subject, string $rule, string $classification, mixed $before, mixed $after, string $explanation): array
    {
        return [
            'surface' => $surface,
            'subject' => $subject,
            'rule' => $rule,
            'classification' => $classification,
            'before_hash' => self::hash($before),
            'after_hash' => self::hash($after),
            'explanation' => $explanation,
        ];
    }

    /** sha256 of the canonical JSON of a value (maps sorted by key, lists kept in order); null for an absent side. */
    public static function hash(mixed $value): ?string
    {
        return $value === null ? null : hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param  list<ChangeRow> $changes
     * @return list<ChangeRow>
     */
    public static function sorted(array $changes): array
    {
        usort($changes, static fn (array $a, array $b): int => [$a['surface'], $a['subject'], $a['rule']] <=> [$b['surface'], $b['subject'], $b['rule']]);

        return $changes;
    }

    /** @param list<ChangeRow> $changes 0 = nothing blocks, 1 = a BREAK or REVIEW */
    public static function exitCode(array $changes): int
    {
        foreach ($changes as $change) {
            if ($change['classification'] !== self::ADD) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * The exit of one adapter run: 2 when an input cannot be trusted (ContractError), else the code of its changes.
     *
     * @param callable(): list<ChangeRow> $check
     */
    public static function exitOf(callable $check): int
    {
        try {
            return self::exitCode($check());
        } catch (ContractError) {
            return 2;
        }
    }
}
