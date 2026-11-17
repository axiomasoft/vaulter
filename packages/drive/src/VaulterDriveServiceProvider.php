<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The drive package only offers {@see DriveModule}; the core module kernel registers what the module
 * declares, so a host that switches the drive off (`vaulter.modules.drive = false`) gets none of it.
 */
#[Api]
final class VaulterDriveServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(DriveModule::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-drive');
    }
}
