<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Content\ScanBackfillRun;
use Vaulter\Drive\Models\Content\ScanEvidenceRecord;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Content\ScanPolicyState;
use Vaulter\Drive\Models\Content\ScanRequest;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * Immutable compiled configuration snapshot for the `vaulter-drive` domain package.
 */
#[Api(since: '1.0')]
final readonly class DriveOptions implements OptionsFactory
{
    /**
     * @param list<class-string<FileHandler>> $handlers
     */
    public function __construct(
        public ProfilesOptions $profiles = new ProfilesOptions,
        public ScanOptions $scan = new ScanOptions,
        public DriveFeaturesOptions $features = new DriveFeaturesOptions,
        public DrivesOptions $drives = new DrivesOptions,
        public AccessOptions $access = new AccessOptions,
        public ListingOptions $listing = new ListingOptions,
        public SharingOptions $sharing = new SharingOptions,
        public DownloadsOptions $downloads = new DownloadsOptions,
        public ChangesOptions $changes = new ChangesOptions,
        public array $handlers = [],
        public UploadsOptions $uploads = new UploadsOptions,
        public TrashOptions $trash = new TrashOptions,
        public QuotaOptions $quota = new QuotaOptions,
        public DriveScheduleOptions $schedule = new DriveScheduleOptions,
        public DriveModelsOptions $models = new DriveModelsOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter-drive'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, [
            'profiles',
            'scan',
            'features',
            'drives',
            'access',
            'listing',
            'sharing',
            'downloads',
            'changes',
            'handlers',
            'uploads',
            'trash',
            'quota',
            'schedule',
            'models',
        ], $path);

        $profiles = ProfilesOptions::fromArray($config['profiles'] ?? [], $path.'.profiles', $validator);
        $features = DriveFeaturesOptions::fromArray($config['features'] ?? [], $path.'.features', $validator);
        $drives = DrivesOptions::fromArray($config['drives'] ?? [], $path.'.drives', $validator);
        $access = AccessOptions::fromArray($config['access'] ?? [], $path.'.access', $validator);
        $listing = ListingOptions::fromArray($config['listing'] ?? [], $path.'.listing', $validator);
        $sharing = SharingOptions::fromArray($config['sharing'] ?? [], $path.'.sharing', $validator);
        $downloads = DownloadsOptions::fromArray($config['downloads'] ?? [], $path.'.downloads', $validator);
        $changes = ChangesOptions::fromArray($config['changes'] ?? [], $path.'.changes', $validator);
        $uploads = UploadsOptions::fromArray($config['uploads'] ?? [], $path.'.uploads', $validator);
        $scan = ScanOptions::fromSections($config['scan'] ?? [], $uploads->scan, $path.'.scan', $validator);
        $trash = TrashOptions::fromArray($config['trash'] ?? [], $path.'.trash', $validator);
        $quota = QuotaOptions::fromArray($config['quota'] ?? [], $path.'.quota', $validator);
        $schedule = DriveScheduleOptions::fromArray($config['schedule'] ?? [], $path.'.schedule', $validator);
        $models = DriveModelsOptions::fromArray($config['models'] ?? [], $path.'.models', $validator);

        $handlersRaw = $config['handlers'] ?? [];
        $handlers = [];
        foreach (is_array($handlersRaw) ? $handlersRaw : [] as $class) {
            if (is_string($class) && is_a($class, FileHandler::class, true)) {
                /** @var class-string<FileHandler> $class */
                $handlers[] = $class;
            }
        }

        $validator->failIfAny();

        return new self(
            profiles: $profiles,
            scan: $scan,
            features: $features,
            drives: $drives,
            access: $access,
            listing: $listing,
            sharing: $sharing,
            downloads: $downloads,
            changes: $changes,
            handlers: $handlers,
            uploads: $uploads,
            trash: $trash,
            quota: $quota,
            schedule: $schedule,
            models: $models,
        );
    }

    /**
     * Resolve the configured drive model FQCN for `$key`.
     *
     * @return (
     *     $key is 'scan_backfill_run' ? class-string<ScanBackfillRun> : (
     *     $key is 'scan_policy_state' ? class-string<ScanPolicyState> : (
     *     $key is 'scan_evidence' ? class-string<ScanEvidenceRecord> : (
     *     $key is 'scan_request' ? class-string<ScanRequest> : (
     *     $key is 'scan_obligation' ? class-string<ScanObligation> : (
     *     $key is 'drive' ? class-string<Drive> : (
     *     $key is 'node' ? class-string<Node> : (
     *     $key is 'file' ? class-string<File> : (
     *     $key is 'file_revision' ? class-string<FileRevision> : (
     *     $key is 'file_revision_blob' ? class-string<FileRevisionBlob> : (
     *     $key is 'grant' ? class-string<NodeGrant> : (
     *     $key is 'node_share' ? class-string<NodeShare> : (
     *     $key is 'activity' ? class-string<Activity> : (
     *     $key is 'upload_session' ? class-string<UploadSession> : (
     *     $key is 'upload_part' ? class-string<UploadPart> : class-string<Model>))))))))))))))
     * )
     */
    #[Internal]
    public function model(string $key): string
    {
        return $this->models->classFor($key);
    }

    /**
     * Whether a drive feature flag is enabled globally.
     */
    public function featureEnabled(string $feature): bool
    {
        return $this->features->flag($feature);
    }
}
