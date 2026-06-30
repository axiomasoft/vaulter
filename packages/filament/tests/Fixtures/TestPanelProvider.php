<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Vaulter\Filament\VaulterPlugin;

/**
 * A minimal Filament panel that registers the Vaulter plugin, so the resource list
 * pages can be rendered and their actions exercised in Livewire tests.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins([
                VaulterPlugin::make(),
            ]);
    }
}
