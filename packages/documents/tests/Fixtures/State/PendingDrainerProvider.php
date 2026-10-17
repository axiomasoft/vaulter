<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\State;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

final class PendingDrainerProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(PendingDrainerModule::class);
    }
}
