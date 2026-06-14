<?php

declare(strict_types=1);

namespace DocVault\Filament;

use DocVault\Filament\Resources\DocumentResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;

/**
 * DocVault Filament plugin (Filament 5).
 *
 * Register it in your PanelProvider:
 *
 *   $panel->plugins([ DocVaultPlugin::make() ]);
 */
final class DocVaultPlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    #[Override]
    public function getId(): string
    {
        return 'docvault';
    }

    #[Override]
    public function register(Panel $panel): void
    {
        $panel->resources([
            DocumentResource::class,
        ]);
    }

    #[Override]
    public function boot(Panel $panel): void
    {
        //
    }
}
