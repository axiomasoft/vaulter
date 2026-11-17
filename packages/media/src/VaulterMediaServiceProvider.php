<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Vaulter\Modules\OffersModules;

/**
 * Vaulter Media — the content-addressed file/blob layer (CAS), extracted from vaulter-core. This package
 * owns the concrete CAS engine (ContentStore / StorageDriver / BlobStore implementations, the two-phase GC
 * and reconciler and their commands); the seam contracts, the Blob model/schema and the per-file ACL stay
 * in core (ACL-agnostic CAS — the media layer never touches AccessResolver).
 *
 * Dependency direction is one-way: media requires core, core does not require media at runtime. The
 * provider only offers {@see MediaModule}; the core module kernel registers what the module declares.
 */
#[Api]
final class VaulterMediaServiceProvider extends ServiceProvider
{
    use OffersModules;

    public function register(): void
    {
        $this->offerModule(MediaModule::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'vaulter-media');
    }
}
