<?php

declare(strict_types=1);

namespace Vaulter\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Override;
use Vaulter\Filament\Resources\DocumentResource;
use Vaulter\Filament\Resources\FolderResource;
use Vaulter\Filament\Resources\ShareResource;

/**
 * Vaulter Filament plugin (Filament 5).
 *
 * Register it in your PanelProvider:
 *
 *   $panel->plugins([ VaulterPlugin::make() ]);
 */
final class VaulterPlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    #[Override]
    public function getId(): string
    {
        return 'vaulter';
    }

    #[Override]
    public function register(Panel $panel): void
    {
        $panel->resources([
            DocumentResource::class,
            FolderResource::class,
            ShareResource::class,
        ]);
    }

    #[Override]
    public function boot(Panel $panel): void
    {
        //
    }
}
