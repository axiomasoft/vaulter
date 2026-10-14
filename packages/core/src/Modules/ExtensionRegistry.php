<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Container\Container;

/**
 * The contributions of the active modules to each slot, read from the sealed registry. The consumer
 * that owns a slot enforces its kind (veto, narrowing, batch fragments, one call of `next`).
 */
final readonly class ExtensionRegistry
{
    public function __construct(
        private CompiledModules $modules,
        private Container $container,
    ) {}

    /**
     * Ordered by priority ascending, then id; empty when no active module defines the slot.
     *
     * @param  class-string           $slot
     * @return list<SlotContribution>
     */
    public function contributions(string $slot): array
    {
        return $this->modules->contributions[$slot] ?? [];
    }

    /**
     * The contributions resolved, in the same order.
     *
     * @template T of object
     *
     * @param  class-string<T> $slot
     * @return list<T>
     */
    public function resolve(string $slot): array
    {
        return array_map(fn (SlotContribution $c): object => $this->make($slot, $c), $this->contributions($slot));
    }

    /**
     * @param  class-string $slot
     * @return list<string>
     */
    public function handlerKeys(string $slot): array
    {
        return array_values(array_filter(array_map(static fn (SlotContribution $c): ?string => $c->key, $this->contributions($slot)), static fn (?string $key): bool => $key !== null));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $slot
     *
     * @throws MissingExtensionException for a key no active module contributes
     *
     * @return T
     */
    public function handler(string $slot, string $key): object
    {
        foreach ($this->contributions($slot) as $contribution) {
            if ($contribution->key === $key) {
                return $this->make($slot, $contribution);
            }
        }

        throw MissingExtensionException::handler($slot, $key);
    }

    /**
     * The implementation class of the contribution under `$key`, for a consumer that builds it with
     * its own arguments (a drive extension takes the handle and the operation context).
     *
     * @param class-string $slot
     *
     * @throws MissingExtensionException for a key no active module contributes
     *
     * @return class-string
     */
    public function handlerClass(string $slot, string $key): string
    {
        foreach ($this->contributions($slot) as $contribution) {
            if ($contribution->key === $key) {
                return $contribution->implementation;
            }
        }

        throw MissingExtensionException::handler($slot, $key);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $slot
     * @return T
     */
    private function make(string $slot, SlotContribution $contribution): object
    {
        $instance = $this->container->make($contribution->implementation);
        if (!$instance instanceof $slot) {
            throw MissingExtensionException::handler($slot, $contribution->key ?? $contribution->id);
        }

        return $instance;
    }
}
