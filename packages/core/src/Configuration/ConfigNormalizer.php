<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

#[Api]
final class ConfigNormalizer
{
    /** @var array<string, string> */
    private array $defaultPaths = [];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    /**
     * Keys whose configured array REPLACES the shipped one: when the host sets such a map or list, none of
     * the package's nested defaults is merged back into it.
     */
    private const array REPLACEMENT_MAPS = [
        'vaulter.ids.strategies',
        'vaulter-drive.handlers',
        'vaulter-documents.types',
        'vaulter-drive.profiles.providers',
        'vaulter-drive.access.permission_drivers',
        'vaulter-drive.access.gate.abilities',
        'vaulter-drive.access.spatie.permissions',
        'vaulter-azgard.permissions',
        'vaulter.routes.middleware.authenticated',
        'vaulter-drive.downloads.inline_mime_types',
        'vaulter-drive.uploads.allowed_mime_types',
        'vaulter-drive.uploads.resumable.metadata.allowed_keys',
        'vaulter-documents.tiptap.extra_nodes',
        'vaulter-documents.tiptap.extra_marks',
        'vaulter-documents.tiptap.extra_attributes',
        'vaulter-documents.tiptap.url_attributes',
    ];

    public function registerDefaults(string $root, string $path): void
    {
        if (($this->defaultPaths[$root] ?? null) !== $path) {
            unset($this->defaults[$root]);
        }
        $this->defaultPaths[$root] = $path;
    }

    /** @return array<string, array<string, mixed>> */
    public function packageDefaults(): array
    {
        $defaults = [];
        foreach ($this->defaultPaths as $root => $path) {
            $this->defaults[$root] ??= $this->loadDefaults($path);
            $defaults[$root] = $this->defaults[$root];
        }

        return $defaults;
    }

    /** @param array<string, array<string, mixed>> $packageDefaults */
    public function normalize(Repository $config, array $packageDefaults): void
    {
        $defaults = $this->stringKeyedEntries(Arr::dot($packageDefaults));
        // Decided before any default is written: filling a map's first key must not make the map look host-configured.
        $configuredMaps = array_filter(self::REPLACEMENT_MAPS, static fn (string $map): bool => $config->has($map));

        foreach ($defaults as $key => $value) {
            $insideReplacementMap = false;
            foreach ($configuredMaps as $map) {
                if (str_starts_with($key, $map.'.')) {
                    $insideReplacementMap = true;
                    break;
                }
            }
            if (!$insideReplacementMap && !$config->has($key)) {
                $config->set($key, $value);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadDefaults(string $path): array
    {
        $values = require $path;
        if (!is_array($values)) {
            throw new InvalidConfigurationException('[Vaulter] Package config file "'.$path.'" must return an array.');
        }
        $defaults = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidConfigurationException('[Vaulter] Package config file "'.$path.'" must use string root keys.');
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyedEntries(array $values): array
    {
        $entries = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }
}
