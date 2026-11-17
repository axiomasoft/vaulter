<?php

declare(strict_types=1);

namespace Vaulter\Media\Providers;

use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Media\Cas\BlobPinReferences;
use Vaulter\Media\Console\GcBlobsCommand;
use Vaulter\Media\Console\ReconcileBlobsCommand;
use Vaulter\Media\Console\ScrubBlobsCommand;
use Vaulter\Media\Http\Problems\MediaProblemCodes;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\VaulterExtensionFacets;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see MediaModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class MediaPackageRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        VaulterExtensionFacets::register($app, MediaModule::class, dirname(__DIR__, 2).'/composer.json');

        ModuleCatalog::of($app)->offerPlugin(new PluginModuleSpec(
            plugin: MediaModule::class,
            activation: Activation::Auto,
            protocolVersion: '1.0',
            commands: [
                GcBlobsCommand::class,
                ReconcileBlobsCommand::class,
                ScrubBlobsCommand::class,
            ],
            schedules: [SchedulingRegistrar::class],
            bindings: [BindingsRegistrar::class],
            problems: [MediaProblemCodes::class],
            heldReferences: BlobPinReferences::class,
        ));
    }

    #[Override]
    public function boot(Application $app): void {}
}
