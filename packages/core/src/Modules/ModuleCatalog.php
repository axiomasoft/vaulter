<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/**
 * The manifests offered by the package providers while the application registers. The kernel reads
 * them once, in its booting callback, and seals the catalogue: a provider registered after boot cannot
 * offer a module any more.
 */
#[Internal]
final class ModuleCatalog
{
    /** @var list<string> */
    private array $offered = [];

    private bool $sealed = false;

    /** The catalogue of the application; any provider may be the first to offer, so it binds itself. */
    public static function of(Container $app): self
    {
        $app->singletonIf(self::class);

        return $app->make(self::class);
    }

    /** @param class-string<VaulterModule|ExtensionPlugin> $manifest */
    public function offer(string $manifest): void
    {
        if ($this->sealed) {
            throw RegistryFrozenException::forRegistry('module');
        }
        if (!in_array($manifest, $this->offered, true)) {
            $this->offered[] = $manifest;
        }
    }

    /** @return list<string> in the order of the offers */
    public function offered(): array
    {
        return $this->offered;
    }

    public function seal(): void
    {
        $this->sealed = true;
    }
}
