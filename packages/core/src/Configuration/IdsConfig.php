<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class IdsConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function strategy(): string
    {
        $value = $this->config->get('vaulter.ids.strategy', 'ulid');

        return is_string($value) && $value !== '' ? $value : 'ulid';
    }

    /** @return array<string, class-string<IdStrategy>> */
    public function strategies(): array
    {
        $value = $this->config->get('vaulter.ids.strategies', []);

        if (!is_array($value)) {
            return [];
        }

        $strategies = [];

        foreach ($value as $key => $class) {
            if (is_string($key) && is_string($class) && is_a($class, IdStrategy::class, allow_string: true)) {
                $strategies[$key] = $class;
            }
        }

        /** @var array<string, class-string<IdStrategy>> $strategies */
        return $strategies;
    }

    /** @return 'string'|'bigint'|'uuid'|'ulid' */
    public function hostKeys(): string
    {
        $value = $this->config->get('vaulter.ids.host_keys', 'string');

        if (!is_string($value) || !in_array($value, ['string', 'bigint', 'uuid', 'ulid'], true)) {
            $label = is_scalar($value) || $value === null ? (string) $value : get_debug_type($value);

            throw new \InvalidArgumentException('[Vaulter] Unknown vaulter.ids.host_keys ['.$label.']; expected string|bigint|uuid|ulid.');
        }

        return $value;
    }

    public function usesUlids(): bool
    {
        return $this->strategy() === 'ulid';
    }
}
