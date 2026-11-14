<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\Concerns;

use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Configuration\FilamentConfig;

/** The navigation group comes from `vaulter-filament.navigation_group`, not a hardcoded label. */
#[Internal]
trait ConfiguresNavigationGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(FilamentConfig::class)->navigationGroup();
    }
}
