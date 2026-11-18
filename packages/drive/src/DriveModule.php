<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Override;
use Packman\Attributes\Api;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Contracts\Security\ProductionInvariant;
use Vaulter\Doctor\Checks\RoutesAuthCheck;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Activity\ActivitySink;
use Vaulter\Drive\Changes\ChangeJournalSink;
use Vaulter\Drive\Configuration\DriveOptions;
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
use Vaulter\Drive\Content\ContentAvailabilityGuard;
use Vaulter\Drive\Content\FileContentLifecycle;
use Vaulter\Drive\Content\ShortcutPurgeLifecycle;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Contracts\Handlers\NodePresenter;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Contracts\Profiles\ProfileOption;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Doctor\Checks\AccessDriverCheck;
use Vaulter\Drive\Doctor\Checks\SchemaIntegrityCheck;
use Vaulter\Drive\Doctor\DedupScopeCheck;
use Vaulter\Drive\Doctor\DiskProbeCheck;
use Vaulter\Drive\Doctor\DrivesDeletingCheck;
use Vaulter\Drive\Doctor\DrivesUsageCheck;
use Vaulter\Drive\Doctor\IntlCheck;
use Vaulter\Drive\Doctor\ScanObligationsCheck;
use Vaulter\Drive\Doctor\ScanPolicyCheck;
use Vaulter\Drive\Doctor\ScanScannerCheck;
use Vaulter\Drive\Doctor\ScanScopeCheck;
use Vaulter\Drive\Doctor\UploadsTmpCheck;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Errors\DriveProblemDefinitions;
use Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Drive\Handlers\Builtin\PreviewHandler;
use Vaulter\Drive\Handlers\NodePresenter as CoreNodePresenter;
use Vaulter\Drive\Http\Problems\DriveProblemCodes;
use Vaulter\Drive\Http\Problems\DriveSliceProblemCodes;
use Vaulter\Drive\Http\Support\DriveRouteLabels;
use Vaulter\Drive\Operations\DriveOperationPolicies;
use Vaulter\Drive\Providers\AccessRegistrar;
use Vaulter\Drive\Providers\BindingsRegistrar;
use Vaulter\Drive\Providers\DriveSliceRegistrar;
use Vaulter\Drive\Providers\SchedulingRegistrar;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Drive\Schema\DriveSchemaSets;
use Vaulter\Drive\Security\PermissionDriverInvariant;
use Vaulter\Drive\Uploads\Inspection\FilenameInspector;
use Vaulter\Drive\Uploads\Inspection\MimeConsistencyInspector;
use Vaulter\Drive\Uploads\Inspection\ScanDispatchInspector;
use Vaulter\Drive\Uploads\Inspection\SizeInspector;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Events\EventSink;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/**
 * The drive extension plugin (`vaulter.drive`): tree/file/upload/access/share/change-feed domain,
 * schema, error catalog, capabilities, extension slots, and diagnostic checks.
 */
#[Api(since: '1.0')]
final class DriveModule implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

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
        'media.disk.probe' => DiskProbeCheck::class,
        'media.dedup.scope' => DedupScopeCheck::class,
        'core.routes.auth' => RoutesAuthCheck::class,
        'core.access.driver' => AccessDriverCheck::class,
        'core.schema.integrity' => SchemaIntegrityCheck::class,
    ];

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.drive',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.media'],
            resourceRoot: dirname(__DIR__).'/resources',
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter-drive';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-drive.php';
    }

    #[Override]
    public function optionsClass(): string
    {
        return DriveOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'drives',
            'nodes',
            'files',
            'file_revisions',
            'file_revision_blobs',
            'scan_policy_states',
            'scan_obligations',
            'scan_backfill_runs',
            'scan_requests',
            'scan_evidence',
            'node_grants',
            'node_shares',
            'node_user_states',
            'upload_sessions',
            'upload_parts',
            'activity',
            'drive_change_heads',
            'drive_changes',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [DriveSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [DriveSchema::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            DriveHandleFactoryContract::class => Cardinality::RequiredSingle->value,
            NodePresenter::class => Cardinality::RequiredSingle->value,
        ];
    }

    /** @return array<class-string, class-string|array{config: string, default?: class-string|null}> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            DriveHandleFactoryContract::class => DriveHandleFactory::class,
            NodePresenter::class => CoreNodePresenter::class,
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality?: string}> */
    #[Override]
    public function definedSlots(): array
    {
        $handler = ['kind' => SlotKind::Handler->value, 'cardinality' => Cardinality::Many->value];
        $guard = ['kind' => SlotKind::Guard->value, 'cardinality' => Cardinality::Many->value];

        return [
            NodeSnapshotContributor::class => ['kind' => SlotKind::Contributor->value, 'cardinality' => Cardinality::Many->value],
            DriveExtension::class => $handler,
            PermissionDriver::class => $handler,
            FileHandler::class => $handler,
            KindPresenter::class => $handler,
            NodeContentLifecycle::class => $handler,
            UploadProtocolHandler::class => $handler,
            UploadInspector::class => $guard,
            DownloadGuard::class => $guard,
            ProfileOption::class => $handler,
        ];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        $contributions = [
            ['slot' => ProductionInvariant::class, 'id' => 'drive.permission-driver', 'implementation' => PermissionDriverInvariant::class, 'key' => 'drive.permission-driver'],
            ['slot' => EventSink::class, 'id' => 'drive.activity', 'implementation' => ActivitySink::class],
            ['slot' => EventSink::class, 'id' => 'drive.changes', 'implementation' => ChangeJournalSink::class],
            ['slot' => RouteLabelSource::class, 'id' => 'drive.routes', 'implementation' => DriveRouteLabels::class],
            ['slot' => PermissionDriver::class, 'id' => 'drive.permission.database', 'implementation' => DatabasePermissionDriver::class, 'key' => 'database'],
            ['slot' => PermissionDriver::class, 'id' => 'drive.permission.gate', 'implementation' => GatePermissionDriver::class, 'key' => 'gate'],
            ['slot' => PermissionDriver::class, 'id' => 'drive.permission.spatie', 'implementation' => SpatiePermissionDriver::class, 'key' => 'spatie'],
            ['slot' => FileHandler::class, 'id' => 'drive.file-handler.preview', 'implementation' => PreviewHandler::class, 'key' => 'vaulter/preview'],
            ['slot' => FileHandler::class, 'id' => 'drive.file-handler.download', 'implementation' => DownloadOnlyHandler::class, 'key' => 'vaulter/download'],
            ['slot' => NodeContentLifecycle::class, 'id' => 'drive.lifecycle.file', 'implementation' => FileContentLifecycle::class, 'key' => NodeKind::File->value],
            ['slot' => NodeContentLifecycle::class, 'id' => 'drive.lifecycle.shortcut', 'implementation' => ShortcutPurgeLifecycle::class, 'key' => NodeKind::Shortcut->value],
            ['slot' => UploadProtocolHandler::class, 'id' => 'drive.upload.tus', 'implementation' => TusUploadHandler::class, 'key' => UploadProtocol::Tus->value],
            ['slot' => UploadProtocolHandler::class, 'id' => 'drive.upload.s3-multipart', 'implementation' => S3MultipartHandler::class, 'key' => UploadProtocol::S3Multipart->value],
            ['slot' => UploadInspector::class, 'id' => 'drive.inspector.filename', 'implementation' => FilenameInspector::class, 'priority' => -100],
            ['slot' => UploadInspector::class, 'id' => 'drive.inspector.size', 'implementation' => SizeInspector::class, 'priority' => -90],
            ['slot' => UploadInspector::class, 'id' => 'drive.inspector.mime-consistency', 'implementation' => MimeConsistencyInspector::class, 'priority' => -80],
            ['slot' => UploadInspector::class, 'id' => 'drive.inspector.scan-dispatch', 'implementation' => ScanDispatchInspector::class, 'priority' => 0],
            ['slot' => DownloadGuard::class, 'id' => 'drive.content-availability', 'implementation' => ContentAvailabilityGuard::class],
        ];

        foreach (self::DOCTOR_CHECKS as $key => $check) {
            $contributions[] = ['slot' => DoctorCheck::class, 'id' => $key, 'implementation' => $check, 'key' => $key];
        }

        return $contributions;
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return array_values(self::DOCTOR_CHECKS);
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [DriveProblemDefinitions::class];
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        $root = dirname(__DIR__);

        return new PluginModuleSpec(
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
        );
    }
}
