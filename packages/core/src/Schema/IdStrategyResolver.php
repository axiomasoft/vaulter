<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Exceptions\Schema\UnknownIdStrategyException;

/**
 * Resolves a primary-key strategy by its config key (ulid|uuid7|bigint).
 * Falls back to configuration key vaulter.ids.strategy when no key is supplied; the
 * key->class map lives in configuration key vaulter.ids.strategies, and instances come
 * from the container so strategies may declare their own dependencies later.
 *
 * The resolver is registered as a container singleton, so the resolved instance
 * map is cached for the lifetime of the request/worker.
 */
#[Internal]
final class IdStrategyResolver
{
    private IdsOptions $activeOptions;

    public function __construct(private readonly IdsOptions $idsOptions)
    {
        $this->activeOptions = $idsOptions;
    }

    /**
     * @var array<string, IdStrategy>
     */
    private array $resolved = [];

    public function resolve(?string $key = null): IdStrategy
    {
        $container = Container::getInstance();
        $options = $container->bound(IdsOptions::class) ? $container->make(IdsOptions::class) : $this->idsOptions;
        if ($options !== $this->activeOptions) {
            $this->activeOptions = $options;
            $this->resolved = [];
        }

        $key ??= $options->strategy;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $strategies = $options->strategies;

        if (!isset($strategies[$key])) {
            throw UnknownIdStrategyException::forKey($key, array_keys($strategies));
        }

        /** @var IdStrategy $strategy */
        $strategy = app()->make($strategies[$key]);

        return $this->resolved[$key] = $strategy;
    }
}
