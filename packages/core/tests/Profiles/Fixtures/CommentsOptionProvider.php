<?php

declare(strict_types=1);

namespace Vaulter\Tests\Profiles\Fixtures;

use Illuminate\Support\ServiceProvider;
use Vaulter\Modules\OffersModules;

/** Offers the comments-owning fixture module and registers nothing itself. */
final class CommentsOptionProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(CommentsOptionModule::class);
    }
}
