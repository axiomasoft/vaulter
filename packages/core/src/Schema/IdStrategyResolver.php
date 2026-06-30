<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Vaulter\Contracts\IdStrategy;
use Vaulter\Exceptions\Schema\UnknownIdStrategyException;
use Vaulter\Support\Config;

/**
 * Resolves a primary-key strategy by its config key (ulid|uuid7|bigint).
 * Falls back to config('vaulter.ids.default') when no key is supplied; the
 * key->class map lives in config('vaulter.ids.strategies'), and instances come
 * from the container so strategies may declare their own dependencies later.
 *
 * The resolver is registered as a container singleton, so the resolved instance
 * map is cached for the lifetime of the request/worker.
 */
final class IdStrategyResolver
{
    /**
     * @var array<string, IdStrategy>
     */
    private array $resolved = [];

    public function resolve(?string $key = null): IdStrategy
    {
        $key ??= Config::idStrategy();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $strategies = Config::idStrategies();

        if (!isset($strategies[$key])) {
            throw UnknownIdStrategyException::forKey($key, array_keys($strategies));
        }

        /** @var IdStrategy $strategy */
        $strategy = app()->make($strategies[$key]);

        return $this->resolved[$key] = $strategy;
    }
}
