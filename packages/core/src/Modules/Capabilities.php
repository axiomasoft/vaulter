<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * The capabilities of the active modules, read from the sealed registry only: a consumer asks by the
 * contract it needs, never probes the container for it.
 */
#[Api]
final readonly class Capabilities
{
    #[Internal]
    public function __construct(
        private CompiledModules $modules,
        private Container $container,
    ) {}

    /** @param class-string $contract */
    public function has(string $contract): bool
    {
        return $this->available($contract) !== [];
    }

    /**
     * The single provider of `$contract`.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @throws MissingExtensionException when no active module provides it, or it takes many providers
     *
     * @return T
     */
    public function require(string $contract): object
    {
        if (($this->modules->capabilities[$contract] ?? null)?->cardinality === Cardinality::Many) {
            throw MissingExtensionException::notSingle($contract);
        }
        $classes = $this->available($contract);
        if ($classes === []) {
            throw MissingExtensionException::capability($contract);
        }

        // A single capability is a container singleton under its contract (ModuleApplier::provide): the one
        // instance every consumer shares, and the seam a host or test decorates with extend().
        $instance = $this->container->make($contract);
        if (!$instance instanceof $contract) {
            throw MissingExtensionException::capability($contract);
        }

        return $instance;
    }

    /**
     * Every available provider of `$contract`, in the order of the modules.
     *
     * @template T of object
     *
     * @param  class-string<T> $contract
     * @return list<T>
     */
    public function all(string $contract): array
    {
        return array_map(fn (string $class): object => $this->make($contract, $class), $this->available($contract));
    }

    /**
     * The implementation classes of the active providers; a config provider counts while its key (or its
     * default) names a class.
     *
     * @return list<class-string>
     */
    private function available(string $contract): array
    {
        if (!isset($this->modules->capabilities[$contract])) {
            return [];
        }
        $classes = [];
        foreach ($this->modules->providers[$contract] ?? [] as $provider) {
            $class = $provider->configKey === null
                ? $provider->implementation
                : $this->configured($provider->configKey) ?? $provider->default;
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return class-string|null */
    private function configured(string $key): ?string
    {
        $value = $this->container->make(Repository::class)->get($key);

        /** @var class-string|null */
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $contract
     * @param  class-string    $class
     * @return T
     */
    private function make(string $contract, string $class): object
    {
        $instance = $this->container->make($class);
        if (!$instance instanceof $contract) {
            throw MissingExtensionException::capability($contract);
        }

        return $instance;
    }
}
