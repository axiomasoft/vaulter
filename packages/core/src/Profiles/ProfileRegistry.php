<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Vaulter\Exceptions\Profiles\UnknownProfileException;

/** Named profiles hydrated from config, with the default registered before overrides. */
final class ProfileRegistry
{
    /**
     * @var array<string, Profile>
     */
    private array $panels = [];

    public function register(Profile $panel): void
    {

        $this->panels[$panel->getId()] = $panel;
    }

    public function registerProvider(ProfileProvider $provider): void
    {
        $panel = $provider->resolve();

        $this->register($panel);

    }

    /**
     * Build a registry from a list of provider FQCN plus the default provider.
     * The default is registered FIRST so an explicit panel may override it.
     *
     * @param array<int, class-string<ProfileProvider>> $providerClasses
     * @param class-string<ProfileProvider>             $defaultClass
     */
    public static function fromConfig(array $providerClasses, string $defaultClass): self
    {
        $registry = new self;

        $registry->registerProvider(new $defaultClass);

        foreach ($providerClasses as $providerClass) {
            $registry->registerProvider(new $providerClass);
        }

        return $registry;
    }

    public function get(string $name): Profile
    {
        return $this->panels[$name]
            ?? throw UnknownProfileException::forName($name, array_keys($this->panels));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->panels);
    }

    /**
     * @return array<string, Profile>
     */
    public function all(): array
    {
        return $this->panels;
    }

    public function default(): Profile
    {
        return $this->get('default');
    }
}
