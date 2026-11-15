<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Media\Cas\BlobPinReferences;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Media\Console\ScrubBlobsCommand;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Doctor\BlobIntegrityCheck;
use Vaulter\Media\Doctor\BlobPinsCheck;
use Vaulter\Media\Http\Problems\MediaProblemCodes;
use Vaulter\Media\Providers\BindingsRegistrar;
use Vaulter\Media\Providers\SchedulingRegistrar;
use Vaulter\Media\Schema\MediaSchema;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\HoldsReferences;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;

/**
 * The media module: the content-addressed store engine, the pins that keep blobs alive, its blob
 * maintenance commands and schedules, the blob_unavailable problem, the pins doctor check and the content-scanner
 * and storage-driver capabilities. Auto: the drive needs it and it opens no outbound traffic.
 */
#[Internal]
final class MediaModule implements HoldsReferences, OwnsSchema, VaulterModule
{
    /** The pins other modules keep on the media's blobs, for vaulter:module:uninstall. */
    public static function heldReferences(): string
    {
        return BlobPinReferences::class;
    }

    public static function ownedSchema(): string
    {
        return MediaSchema::class;
    }

    public function id(): ModuleId
    {
        return new ModuleId('media');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['core' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-media', dirname(__DIR__).'/config/vaulter-media.php')
            ->schema(dirname(__DIR__).'/database/migrations')
            ->bindings(BindingsRegistrar::class)
            ->problems(MediaProblemCodes::class)
            ->commands(GcBlobsCommand::class, ReconcileBlobsCommand::class, ScrubBlobsCommand::class)
            ->schedule(SchedulingRegistrar::class)
            // The scanner the host names in `vaulter-drive.scan.scanner`; none configured means no scanning.
            ->defineCapability(ContentScanner::class, Cardinality::OptionalSingle)
            ->providesFromConfig(ContentScanner::class, 'vaulter-drive.scan.scanner')
            // The CAS storage engine the host picks in `vaulter-media.storage_driver` (Flysystem by default).
            ->defineCapability(StorageDriver::class, Cardinality::RequiredSingle)
            ->providesFromConfig(StorageDriver::class, 'vaulter-media.storage_driver', FlysystemStorageDriver::class)
            // The pins per owner type, warning about owners whose module is not active.
            ->slot(DoctorCheck::class, BlobPinsCheck::class, 'media.blobs.pins', key: 'media.blobs.pins')
            // The blobs the integrity scrub marked as damaged.
            ->slot(DoctorCheck::class, BlobIntegrityCheck::class, 'media.blobs.integrity', key: 'media.blobs.integrity');
    }
}
