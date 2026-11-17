<?php

declare(strict_types=1);

namespace Vaulter\Drive\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Vaulter\Drive\Console\MakeFileHandlerCommand;
use Vaulter\Drive\Console\MakePermissionDriverCommand;
use Vaulter\Drive\Console\MakeProfileCommand;
use Vaulter\Drive\Console\PruneChangesCommand;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\ReconcileScanWorkCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\RescanFileRevisionsCommand;
use Vaulter\Drive\Console\ResetChangesCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Drive\Operations\DriveOperationPolicies;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\VaulterExtensionFacets;

/**
 * Registers Packman plugin/extension facets and Vaulter module runtime metadata for {@see DriveModule}.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DrivePackageRegistrar implements PackageRegistrar
{
    /** @var list<string> Associative maps where an explicit host configuration replaces defaults wholesale. */
    private const array REPLACEMENT_MAPS = [
        'vaulter-drive.access.permission_drivers',
        'vaulter-drive.access.gate.abilities',
        'vaulter-drive.access.spatie.permissions',
    ];

    #[Override]
    public function register(Application $app): void
    {
        /** @var array<string, mixed> $preservedMaps */
        $preservedMaps = [];
        $config = $app->bound('config') ? $app->make('config') : null;
        if ($config instanceof Repository) {
            foreach (self::REPLACEMENT_MAPS as $key) {
                if ($config->has($key)) {
                    $preservedMaps[$key] = $config->get($key);
                }
            }
        }

        VaulterExtensionFacets::register($app, DriveModule::class, dirname(__DIR__, 2).'/composer.json');

        if ($config instanceof Repository) {
            foreach ($preservedMaps as $key => $value) {
                $config->set($key, $value);
            }
        }

        $root = dirname(__DIR__, 2);

        ModuleCatalog::of($app)->offerPlugin(new PluginModuleSpec(
            plugin: DriveModule::class,
            activation: Activation::Auto,
            protocolVersion: '1.0',
            routes: [$root.'/routes/drive.php'],
            commands: [
                PurgeTrashCommand::class,
                PurgeDrivesCommand::class,
                RecountDrivesCommand::class,
                PruneRevisionsCommand::class,
                SweepUploadsCommand::class,
                ReconcileScanWorkCommand::class,
                RescanFileRevisionsCommand::class,
                PruneChangesCommand::class,
                ResetChangesCommand::class,
                MakeProfileCommand::class,
                MakeFileHandlerCommand::class,
                MakePermissionDriverCommand::class,
            ],
            schedules: [SchedulingRegistrar::class],
            bindings: [
                DriveSliceRegistrar::class,
                AccessRegistrar::class,
                BindingsRegistrar::class,
            ],
            events: [DriveEventType::class],
            problems: [
                DriveProblemCodes::class,
                DriveSliceProblemCodes::class,
            ],
            operations: [DriveOperationPolicies::class],
        ));
    }

    #[Override]
    public function boot(Application $app): void {}
}
