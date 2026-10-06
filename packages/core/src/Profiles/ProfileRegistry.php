<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use LogicException;
use Vaulter\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Exceptions\Profiles\UnknownProfileException;

final class ProfileRegistry
{
    /** @var array<string, Profile> */
    private array $profiles = [];

    private bool $frozen = false;

    public function __construct(private ?ContainerContract $container = null) {}

    public function register(ProfileProvider|Profile|string $profile): void
    {
        if ($this->frozen) {
            throw new LogicException('The profile registry is frozen.');
        }
        if (is_string($profile)) {
            if (!is_a($profile, ProfileProvider::class, true)) {
                throw new InvalidProfileException('A profile provider FQCN is required.');
            }
            $profile = ($this->container ?? Container::getInstance())->make($profile);
            if (!$profile instanceof ProfileProvider) {
                throw new InvalidProfileException('The container must return a ProfileProvider.');
            }
        }
        if ($profile instanceof ProfileProvider) {
            $profile = $profile->resolve();
        }
        $key = $profile->getKey();
        if ($key === '' || isset($this->profiles[$key])) {
            throw new InvalidProfileException('Profile keys must be nonempty and unique; duplicate "'.$key.'".');
        }
        $snapshot = clone $profile;
        $snapshot->freeze();
        $this->profiles[$key] = $snapshot;
    }

    /** @param list<class-string<ProfileProvider>> $providerClasses
     * @param class-string<ProfileProvider> $defaultClass
     */
    public static function fromConfig(array $providerClasses, string $defaultClass, ?ContainerContract $container = null): self
    {
        $registry = new self($container);
        foreach ([$defaultClass, ...$providerClasses] as $class) {
            $registry->register($class);
        }
        $registry->freeze();

        return $registry;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function get(string $key): Profile
    {
        return $this->profiles[$key] ?? throw UnknownProfileException::forName($key, array_keys($this->profiles));
    }

    public function has(string $key): bool
    {
        return isset($this->profiles[$key]);
    }

    /** @return array<string, Profile> */
    public function all(): array
    {
        return $this->profiles;
    }

    public function default(): Profile
    {
        return reset($this->profiles) ?: throw new InvalidProfileException('The default profile is missing.');
    }
}
