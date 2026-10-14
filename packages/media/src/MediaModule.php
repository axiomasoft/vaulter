<?php

declare(strict_types=1);

namespace Vaulter\Media;

use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Media\Doctor\DedupScopeCheck;
use Vaulter\Media\Doctor\DiskProbeCheck;
use Vaulter\Media\Providers\BindingsRegistrar;
use Vaulter\Media\Providers\SchedulingRegistrar;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * The media module (D22): the content-addressed store engine, its blob maintenance commands and schedules,
 * and its doctor checks. Auto: the drive needs it and it opens no outbound traffic.
 */
final class MediaModule implements VaulterModule
{
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
            ->slot(DoctorCheck::class, DiskProbeCheck::class, 'media.disk.probe', key: 'media.disk.probe')
            ->slot(DoctorCheck::class, DedupScopeCheck::class, 'media.dedup.scope', key: 'media.dedup.scope');
    }
}
