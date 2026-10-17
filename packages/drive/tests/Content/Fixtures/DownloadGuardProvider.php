<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Content\Fixtures;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

final class DownloadGuardProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(DownloadGuardModule::class);
    }
}
