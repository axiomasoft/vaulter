<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Drive\Activity\RecordsDriveActivity;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Drive\Webhooks\RecordsDriveOutbox;
use Vaulter\Support\Config;

/**
 * Vaulter Drive — the file/folder/upload behaviour layer, extracted from vaulter-core
 * (P2 #5 / ADR-0015, D1). This package OWNS the Drive Actions, StorageManager, the
 * DriveHandle factory, the resumable-upload lifecycle, the PurgeTrash/SweepUploads
 * commands, the drive REST routes and the Drive-event Activity/outbox subscribers.
 * Core keeps the schema, the Node/Storage models, Access/Sharing/Activity/Webhooks
 * and the Contracts\Drive seam; media provides the CAS engine.
 *
 * Dependency direction is one-way: drive requires core + media, neither requires
 * drive. A host that wants Drive behaviour installs this package and Laravel
 * auto-discovers this provider, which binds the DriveHandleFactory behind
 * `Vaulter::for()` and merges the swappable upload-model defaults.
 */
final class VaulterDriveServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('vaulter-drive')
            ->hasRoute('drive')
            ->hasCommand(PurgeTrashCommand::class)
            ->hasCommand(SweepUploadsCommand::class);
    }

    public function packageRegistered(): void
    {
        // Own the resumable-upload swappable-model defaults WITHOUT changing the config
        // path hosts use: merge them under the existing `vaulter.models` key. Core no
        // longer ships these two keys, so this merge is authoritative and resolves the
        // class strings Support\Config::uploadSessionModel()/uploadPartModel() read.
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-drive.php', 'vaulter.models');

        // The public `Vaulter::for()` / `Vaulter::forStorage()` entry point (core's
        // VaulterManager) delegates to this factory seam — bound here so core never
        // references a Drive class.
        $this->app->bind(DriveHandleFactory::class, DriveManager::class);

        // Drive domain (M1c): the storage get-or-create + root provisioning service.
        // Its deps (read/store repositories) auto-resolve; binding it as a singleton
        // makes the seam explicit for swaps/tests.
        $this->app->singleton(StorageManager::class);

        // Resumable uploads (M3a / §2.8): the lifecycle coordinator. Its deps
        // (StagedUpload + the store repository) auto-resolve; the two protocol handlers
        // (tus / S3-multipart) auto-resolve through the container.
        $this->app->singleton(UploadSessionManager::class);
    }

    public function packageBooted(): void
    {
        $this->registerActivitySubscriber();
        $this->registerOutboxSubscriber();
    }

    /**
     * Record the Drive file/folder/upload events into the append-only activity feed
     * (§2.9) through the core ActivityRecorder seam. Gated on the 'activity' feature —
     * the twin of core's kernel-event RecordsActivity, split by owning package (P2 #5).
     */
    private function registerActivitySubscriber(): void
    {
        if (!Config::featureEnabled('activity')) {
            return;
        }

        Event::subscribe(RecordsDriveActivity::class);
    }

    /**
     * Stage the Drive file/folder/upload events in the transactional outbox (§2.11)
     * through the core OutboxRecorder seam. Gated on the 'reliability' feature — the
     * twin of core's kernel-event RecordsOutbox, split by owning package (P2 #5).
     */
    private function registerOutboxSubscriber(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        Event::subscribe(RecordsDriveOutbox::class);
    }
}
