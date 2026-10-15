<?php

declare(strict_types=1);

namespace Vaulter\Database\Factories;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Facades\Vaulter;

/**
 * Base of the Vaulter factories. A row is never inserted directly: it is created
 * through the same Operations a host calls, as a system actor, so quota, sequences,
 * blobs, scan obligations and the tree invariants hold for every fixture. The made
 * model only carries the factory's state; store() replaces it with the stored row.
 *
 * @template TModel of Model
 *
 * @extends Factory<TModel>
 */
#[Api]
abstract class VaulterFactory extends Factory
{
    /** @var array<string, mixed> */
    protected array $vaulter = [];

    /**
     * Run the creation as $tenant. Only meaningful when strict tenancy is enabled.
     */
    public function forTenant(int|string $tenant): static
    {
        return $this->withVaulter(['tenant' => $tenant]);
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function withVaulter(array $state): static
    {
        $new = $this->newInstance();
        $new->vaulter = [...$this->vaulter, ...$state];

        return $new;
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return static
     */
    #[\Override]
    protected function newInstance(array $arguments = [])
    {
        $new = parent::newInstance($arguments);
        $new->vaulter = $this->vaulter;

        return $new;
    }

    /**
     * Vaulter rows are created through Operations; a bulk insert would skip every invariant.
     *
     * @param array<string, mixed> $attributes
     */
    #[\Override]
    public function insert(array $attributes = [], ?Model $parent = null): void
    {
        throw new LogicException('Vaulter factories create through Operations: use create(), not insert().');
    }

    /** The stored row for a made one. */
    abstract protected function persist(Model $draft): Model;

    /**
     * @param Collection<int, TModel> $results
     */
    #[\Override]
    protected function store(Collection $results)
    {
        $results->each(function (Model $draft): void {
            $stored = $this->withTenant(fn (): Model => $this->persist($draft));
            $draft->setRawAttributes($stored->getAttributes(), true);
            $draft->setConnection($stored->getConnectionName());
            $draft->exists = true;
            $draft->wasRecentlyCreated = true;
        });
    }

    protected function system(Model $owner, ?string $profile = null): DriveHandle
    {
        return Vaulter::drive($owner, $profile)->asSystem('factory');
    }

    /**
     * A state entry that must be an instance of $class, or null when it was not set.
     *
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T|null
     */
    protected function stateObject(string $key, string $class): ?object
    {
        $value = $this->vaulter[$key] ?? null;
        if ($value === null) {
            return null;
        }

        return $value instanceof $class ? $value : throw new LogicException(sprintf('The factory state "%s" must be a %s.', $key, $class));
    }

    /** A state entry that must be a string, or $default when it was not set. */
    protected function stateText(string $key, string $default): string
    {
        return $this->stateString($key, $default) ?? $default;
    }

    /** A state entry that must be a string, or $default when it was not set; null when it was set to null. */
    protected function stateString(string $key, ?string $default = null): ?string
    {
        $value = array_key_exists($key, $this->vaulter) ? $this->vaulter[$key] : $default;

        return $value === null || is_string($value) ? $value : throw new LogicException(sprintf('The factory state "%s" must be a string.', $key));
    }

    /**
     * A state entry that must be a string-keyed array, or null when it was not set.
     *
     * @return array<string, mixed>|null
     */
    protected function stateArray(string $key): ?array
    {
        $value = $this->vaulter[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new LogicException(sprintf('The factory state "%s" must be an array.', $key));
        }

        $array = [];
        foreach ($value as $name => $item) {
            $array[(string) $name] = $item;
        }

        return $array;
    }

    /**
     * @template T
     *
     * @param  Closure(): T $callback
     * @return T
     */
    protected function withTenant(Closure $callback): mixed
    {
        $tenant = $this->vaulter['tenant'] ?? null;
        if (!is_int($tenant) && !is_string($tenant)) {
            return $callback();
        }

        $previous = app(TenantResolver::class);
        app()->instance(TenantResolver::class, PinnedTenantResolver::over($tenant, $previous));
        try {
            return $callback();
        } finally {
            app()->instance(TenantResolver::class, $previous);
        }
    }
}
