<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Media\Doctor\DedupScopeCheck;
use Vaulter\Media\Doctor\DiskProbeCheck;
use Vaulter\Media\Providers\BindingsRegistrar;
use Vaulter\Media\Providers\SchedulingRegistrar;
use Vaulter\Media\Schema\MediaSchema;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\VaulterModule;

/**
 * The media module (D22): the content-addressed store engine, its blob maintenance commands and schedules,
 * its doctor checks and the content-scanner and storage-driver capabilities. Auto: the drive needs it and it opens no outbound traffic.
 */
#[Internal]
final class MediaModule implements OwnsSchema, VaulterModule
{
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
            ->bindings(BindingsRegistrar::class)
            ->commands(GcBlobsCommand::class, ReconcileBlobsCommand::class)
            ->schedule(SchedulingRegistrar::class)
            // The scanner the host names in `vaulter.scan.scanner`; none configured means no scanning.
            ->defineCapability(ContentScanner::class, Cardinality::OptionalSingle)
            ->providesFromConfig(ContentScanner::class, 'vaulter.scan.scanner')
            // The CAS storage engine the host picks in `vaulter-media.storage_driver` (Flysystem by default).
            ->defineCapability(StorageDriver::class, Cardinality::RequiredSingle)
            ->providesFromConfig(StorageDriver::class, 'vaulter-media.storage_driver', FlysystemStorageDriver::class)
            ->slot(DoctorCheck::class, DiskProbeCheck::class, 'media.disk.probe', key: 'media.disk.probe')
            ->slot(DoctorCheck::class, DedupScopeCheck::class, 'media.dedup.scope', key: 'media.dedup.scope');
    }
}
