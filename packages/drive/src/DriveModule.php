<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use ReflectionClass;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Drivers\GatePermissionDriver;
use Vaulter\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Activity\ActivitySink;
use Vaulter\Attributes\Internal;
use Vaulter\Changes\ChangeJournalSink;
use Vaulter\Console\MakeFileHandlerCommand;
use Vaulter\Console\MakePermissionDriverCommand;
use Vaulter\Console\MakeProfileCommand;
use Vaulter\Console\PruneChangesCommand;
use Vaulter\Console\ResetChangesCommand;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Contracts\Drive\DownloadGuard;
use Vaulter\Contracts\Drive\DriveExtension;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Contracts\Drive\NodeSnapshotContributor;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Contracts\Presentation\KindPresenter;
use Vaulter\Contracts\Profiles\ProfileOption;
use Vaulter\Doctor\Checks\AccessDriverCheck;
use Vaulter\Doctor\Checks\RoutesAuthCheck;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\ReconcileScanWorkCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\RescanFileRevisionsCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\ShortcutPurgeLifecycle;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Doctor\DrivesDeletingCheck;
use Vaulter\Drive\Doctor\DrivesUsageCheck;
use Vaulter\Drive\Doctor\IntlCheck;
use Vaulter\Drive\Doctor\ScanObligationsCheck;
use Vaulter\Drive\Doctor\ScanPolicyCheck;
use Vaulter\Drive\Doctor\ScanScannerCheck;
use Vaulter\Drive\Doctor\ScanScopeCheck;
use Vaulter\Drive\Doctor\UploadsTmpCheck;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Providers\SchedulingRegistrar;
use Vaulter\Drive\Uploads\Inspection\FilenameInspector;
use Vaulter\Drive\Uploads\Inspection\MimeConsistencyInspector;
use Vaulter\Drive\Uploads\Inspection\ScanDispatchInspector;
use Vaulter\Drive\Uploads\Inspection\SizeInspector;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\EventSink;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\Handlers\NodePresenter as CoreNodePresenter;
use Vaulter\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotKind;
use Vaulter\Modules\VaulterModule;
use Vaulter\Providers\AccessRegistrar;
use Vaulter\Providers\DriveSliceRegistrar;
use Vaulter\VaulterServiceProvider;

/**
 * The drive module (D22, D39): everything of the drive package, and the drive slices still located in core
 * until P4 moves them — access (grants, permission drivers, policies), the core routes file of grants,
 * shares, activity and the change feed, the change-journal and scaffolding commands, two doctor checks, the
 * drive's event types with the activity and change-journal event sinks, and the drive part of the core bindings. `vaulter.modules.drive = false` removes all of it.
 */
#[Internal]
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
            ->problems(DriveProblemCodes::class)
            // Located in core until P4 (D39).
            ->problems(DriveSliceProblemCodes::class)
            // Located in core until P4 (D38): the drive's event types and its two event sinks.
            ->events(DriveEventType::class)
            ->slot(EventSink::class, ActivitySink::class, 'drive.activity')
            ->slot(EventSink::class, ChangeJournalSink::class, 'drive.changes')
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
            ->schedule(SchedulingRegistrar::class)
            ->defineCapability(DriveHandleFactoryContract::class, Cardinality::RequiredSingle)
            ->provides(DriveHandleFactoryContract::class, DriveHandleFactory::class)
            ->defineSlot(NodeSnapshotContributor::class, SlotKind::Contributor, Cardinality::Many)
            ->defineSlot(DriveExtension::class, SlotKind::Handler, Cardinality::Many);

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $module->slot(DoctorCheck::class, $check, $key, key: $key);
        }

        $this->capabilities($module);
        $this->slots($module);
    }

    /** The seams the drive owns as capabilities: the handle factory and the node presenter. */
    private function capabilities(ModuleBuilder $module): void
    {
        $module
            ->defineCapability(NodePresenter::class, Cardinality::RequiredSingle)
            ->provides(NodePresenter::class, CoreNodePresenter::class);
    }

    /**
     * The slots the drive defines (D8) and its own contributions, in the order the drive ran them before the
     * slots existed. The permission drivers, file handlers, presenter and lifecycle classes are located in
     * core until P4 (D39).
     */
    private function slots(ModuleBuilder $module): void
    {
        $module
            ->defineSlot(PermissionDriver::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(FileHandler::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(KindPresenter::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(NodeContentLifecycle::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(UploadProtocolHandler::class, SlotKind::Handler, Cardinality::Many)
            ->defineSlot(UploadInspector::class, SlotKind::Guard, Cardinality::Many)
            ->defineSlot(DownloadGuard::class, SlotKind::Guard, Cardinality::Many)
            ->defineSlot(ProfileOption::class, SlotKind::Handler, Cardinality::Many);

        foreach ([
            'database' => DatabasePermissionDriver::class,
            'gate' => GatePermissionDriver::class,
            'spatie' => SpatiePermissionDriver::class,
        ] as $key => $driver) {
            $module->slot(PermissionDriver::class, $driver, 'drive.permission.'.$key, key: $key);
        }

        $module
            ->slot(FileHandler::class, PreviewHandler::class, 'drive.file-handler.preview', key: 'vaulter/preview')
            ->slot(FileHandler::class, DownloadOnlyHandler::class, 'drive.file-handler.download', key: 'vaulter/download')
            ->slot(NodeContentLifecycle::class, FileContentLifecycle::class, 'drive.lifecycle.file', key: NodeKind::File->value)
            ->slot(NodeContentLifecycle::class, ShortcutPurgeLifecycle::class, 'drive.lifecycle.shortcut', key: NodeKind::Shortcut->value)
            ->slot(UploadProtocolHandler::class, TusUploadHandler::class, 'drive.upload.tus', key: UploadProtocol::Tus->value)
            ->slot(UploadProtocolHandler::class, S3MultipartHandler::class, 'drive.upload.s3-multipart', key: UploadProtocol::S3Multipart->value)
            // The inspectors ran by priority descending (100, 90, 80, 0); the slot orders by priority ascending.
            ->slot(UploadInspector::class, FilenameInspector::class, 'drive.inspector.filename', priority: -100)
            ->slot(UploadInspector::class, SizeInspector::class, 'drive.inspector.size', priority: -90)
            ->slot(UploadInspector::class, MimeConsistencyInspector::class, 'drive.inspector.mime-consistency', priority: -80)
            ->slot(UploadInspector::class, ScanDispatchInspector::class, 'drive.inspector.scan-dispatch', priority: 0)
            ->slot(DownloadGuard::class, ContentAvailabilityGuard::class, 'drive.content-availability');
    }

    /** The drive routes file located in core (D39), found from the core provider's class: no Composer lookup, no I/O. */
    private static function coreRoutes(): string
    {
        return dirname((string) (new ReflectionClass(VaulterServiceProvider::class))->getFileName(), 2).'/routes/drive.php';
    }
}
