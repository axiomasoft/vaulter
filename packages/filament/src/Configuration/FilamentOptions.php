<?php

declare(strict_types=1);

namespace Vaulter\Filament\Configuration;

use Illuminate\Support\Arr;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

#[Api]
final readonly class FilamentOptions implements OptionsFactory
{
    /** @param array<array-key, mixed> $values */
    private function __construct(private array $values) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter-filament'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['navigation_group', 'resources'], $path);
        $section = Arr::get($config, 'resources', []);
        if (!is_array($section)) {
            $validator->add($path.'.resources', 'must be an array');
        } else {
            $validator->rejectUnknownKeys($section, ['drive', 'node', 'grant', 'share', 'activity'], $path.'.resources');
        }
        $validator->failIfAny();

        return new self($config);
    }

    public function navigationGroup(): string
    {
        $value = $this->value(key: 'vaulter-filament.navigation_group', default: 'Vaulter');

        return is_string($value) ? $value : 'Vaulter';
    }

    public function resourceEnabled(string $key): bool
    {
        $value = $this->value(key: 'vaulter-filament.resources.'.$key, default: true);

        return is_bool($value) ? $value : true;
    }

    private function value(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, substr($key, strlen('vaulter-filament.')), $default);
    }
}
