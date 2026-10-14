<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use ReflectionClass;
use Vaulter\Console\MakeFileHandlerCommand;
use Vaulter\Console\MakePermissionDriverCommand;
use Vaulter\Console\MakeProfileCommand;
use Vaulter\Console\PruneChangesCommand;
use Vaulter\Console\ResetChangesCommand;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\Checks\AccessDriverCheck;
use Vaulter\Doctor\Checks\RoutesAuthCheck;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\ReconcileScanWorkCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\RescanFileRevisionsCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Doctor\DrivesDeletingCheck;
use Vaulter\Drive\Doctor\DrivesUsageCheck;
use Vaulter\Drive\Doctor\IntlCheck;
use Vaulter\Drive\Doctor\ScanObligationsCheck;
use Vaulter\Drive\Doctor\ScanPolicyCheck;
use Vaulter\Drive\Doctor\ScanScannerCheck;
use Vaulter\Drive\Doctor\ScanScopeCheck;
use Vaulter\Drive\Doctor\UploadsTmpCheck;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Providers\SchedulingRegistrar;
use Vaulter\Modules\Activation;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;
use Vaulter\Providers\AccessRegistrar;
use Vaulter\Providers\DriveSliceRegistrar;
use Vaulter\VaulterServiceProvider;

/**
 * The drive module (D22, D39): everything of the drive package, and the drive slices still located in core
 * until P4 moves them — access (grants, permission drivers, policies), the core routes file of grants,
 * shares, activity and the change feed, the change-journal and scaffolding commands, two doctor checks and
 * the drive part of the core bindings. `vaulter.modules.drive = false` removes all of it.
 */
final class DriveModule implements VaulterModule
{
    /** @var array<string, class-string<DoctorCheck>> doctor key => check */
    private const array DOCTOR_CHECKS = [
        'drive.uploads.tmp' => UploadsTmpCheck::class,
        'drive.intl' => IntlCheck::class,
        'drive.scan.scanner' => ScanScannerCheck::class,
        'drive.scan.policy' => ScanPolicyCheck::class,
        'drive.scan.obligations' => ScanObligationsCheck::class,
        'drive.scan.scope' => ScanScopeCheck::class,
        'drive.drives.deleting' => DrivesDeletingCheck::class,
        'drive.drives.usage' => DrivesUsageCheck::class,
        // Located in core until P4 (D39).
        'core.routes.auth' => RoutesAuthCheck::class,
        'core.access.driver' => AccessDriverCheck::class,
    ];

    public function id(): ModuleId
    {
        return new ModuleId('drive');
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
        return ['core' => '^1.0', 'media' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-drive', dirname(__DIR__).'/config/vaulter-drive.php')
            ->routes(dirname(__DIR__).'/routes/drive.php')
            ->routes(self::coreRoutes())
            ->bindings(DriveSliceRegistrar::class)
            ->bindings(AccessRegistrar::class)
            ->bindings(BindingsRegistrar::class)
            ->commands(
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
            )
            ->schedule(SchedulingRegistrar::class);

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $module->slot(DoctorCheck::class, $check, $key, key: $key);
        }
    }

    /** The drive routes file located in core (D39), found from the core provider's class: no Composer lookup, no I/O. */
    private static function coreRoutes(): string
    {
        return dirname((string) (new ReflectionClass(VaulterServiceProvider::class))->getFileName(), 2).'/routes/drive.php';
    }
}
