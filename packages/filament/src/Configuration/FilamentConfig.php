<?php

declare(strict_types=1);

namespace Vaulter\Filament\Configuration;

use Illuminate\Contracts\Config\Repository;

final readonly class FilamentConfig
{
    public function __construct(private Repository $config) {}

    public function navigationGroup(): string
    {
        $value = $this->config->get(key: 'vaulter-filament.navigation_group', default: 'Vaulter');

        return is_string($value) ? $value : 'Vaulter';
    }

    public function resourceEnabled(string $key): bool
    {
        $value = $this->config->get(key: 'vaulter-filament.resources.'.$key, default: true);

        return is_bool($value) ? $value : true;
    }
}
