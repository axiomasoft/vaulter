<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * The documents package only offers {@see DocumentsModule}: the core module kernel registers its use cases,
 * revisions, comments, export and HTTP routes while the module is active.
 */
#[Api]
final class VaulterDocumentsServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(DocumentsModule::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-documents');
    }
}
