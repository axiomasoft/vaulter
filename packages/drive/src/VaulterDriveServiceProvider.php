<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Drive\Providers\DrivePackageRegistrar;

/**
 * Vaulter Drive — workspace storage trees, files, uploads, ACL grants, sharing, and change journal.
 */
#[Api(since: '1.0')]
final class VaulterDriveServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        DrivePackageRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-drive');
        $this->bootRegistrars();
    }
}
