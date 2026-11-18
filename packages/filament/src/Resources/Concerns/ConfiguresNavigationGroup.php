<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\Concerns;

use Packman\Attributes\Internal;
use UnitEnum;
use Vaulter\Filament\Configuration\FilamentOptions;

/** The navigation group comes from `vaulter-filament.navigation_group`, not a hardcoded label. */
#[Internal]
trait ConfiguresNavigationGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(FilamentOptions::class)->navigationGroup();
    }
}
