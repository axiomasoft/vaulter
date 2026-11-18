<?php

declare(strict_types=1);

namespace Vaulter\Documents;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Documents\Providers\DocumentsPackageRegistrar;

/**
 * Vaulter Documents — collaborative document aggregates, revisions, comments, and exports.
 */
#[Api(since: '1.0')]
final class VaulterDocumentsServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        RegistersPackageRegistrars::register as protected registerRegistrars;
        RegistersPackageRegistrars::boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        DocumentsPackageRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-documents');
        $this->bootRegistrars();
    }
}
