<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Support\Config;

/**
 * Vaulter Media — the content-addressed file/blob layer (CAS), extracted from
 * vaulter-core (P2 #5, ADR decisions D2/D3). This package OWNS the concrete CAS
 * engine (ContentStore / StorageDriver / BlobStore implementations, the two-phase
 * GC + reconciler and their commands); the seam contracts, the Blob model/schema
 * and the per-file ACL stay in core (ACL-agnostic CAS — the media layer never
 * touches AccessResolver).
 *
 * Dependency direction is one-way: media requires core (for the Contracts\Cas\*
 * seam, the Blob/FileVersion models and Support\Config), core does NOT require
 * media at runtime — a host that wants the CAS file layer installs this package
 * and Laravel auto-discovers this provider, which binds the concrete engine.
 */
final class VaulterMediaServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-media')
            ->hasCommand(GcBlobsCommand::class)
            ->hasCommand(ReconcileBlobsCommand::class);
    }

    public function packageRegistered(): void
    {
        // Own the CAS engine defaults WITHOUT changing the config path hosts use:
        // merge the swappable-class defaults under the existing `vaulter.cas` key.
        // Core no longer ships these three keys, so this merge is authoritative and
        // resolves the class strings Support\Config reads below.
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-cas.php', 'vaulter.cas');

        // CAS services (H.3) — swappable via config('vaulter.cas.*'). StorageDriver
        // and ContentStore are stateless wrappers (singletons); BlobStore is the
        // race-free lifecycle orchestrator. These bindings moved out of core with
        // the engine; core code resolves them through the Contracts\Cas\* seam.
        $this->app->singleton(StorageDriver::class, Config::storageDriverClass());
        $this->app->singleton(ContentStore::class, Config::contentStoreClass());
        $this->app->bind(BlobStore::class, Config::blobStoreClass());
    }
}
