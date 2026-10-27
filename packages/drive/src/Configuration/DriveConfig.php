<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Drive\Contracts\Content\ScanWorkSettings;
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
use Vaulter\Drive\Profiles\DefaultProfile;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileProvider;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Models\Cas\Blob;

#[Api]
final readonly class DriveConfig implements ScanWorkSettings
{
    #[Internal]
    public function __construct(private Repository $config) {}

    #[Internal]
    public function rejectMimeMismatch(): bool
    {
        return (bool) $this->config->get('vaulter-drive.uploads.reject_mime_mismatch', true);
    }

    public function scanQueue(): string
    {
        $queue = $this->config->get('vaulter-drive.uploads.scan.queue', 'default');
        if (!is_string($queue) || trim($queue) === '') {
            throw new InvalidConfigurationException('Scan queue must be nonempty.');
        }

        return $queue;
    }

    public function leaseSeconds(): int
    {
        return $this->scanWork('lease_seconds');
    }

    public function scannerTimeoutSeconds(): int
    {
        return $this->scanWork('scanner_timeout_seconds');
    }

    public function maxAttempts(): int
    {
        return $this->scanWork('max_attempts');
    }

    public function scanWork(string $key): int
    {
        $defaults = ['lease_seconds' => 120, 'scanner_timeout_seconds' => 60, 'max_attempts' => 5,
            'publish_cooldown_seconds' => 30, 'recovery_batch' => 100, 'cleanup_batch' => 100];
        if (!isset($defaults[$key])) {
            throw new InvalidConfigurationException('Unknown scan work setting.');
        }
        $value = $this->config->get('vaulter-drive.uploads.scan.'.$key, $defaults[$key]);
        if (!is_int($value) || $value < 1 || (str_ends_with($key, '_batch') && $value > 1000)) {
            throw new InvalidConfigurationException('Invalid scan work '.$key.'.');
        }

        return $value;
    }

    public function validateScanWork(bool $requireBroker = false): void
    {
        $this->scanQueue();
        foreach (['lease_seconds', 'scanner_timeout_seconds', 'max_attempts', 'publish_cooldown_seconds', 'recovery_batch', 'cleanup_batch'] as $key) {
            $this->scanWork($key);
        }
        $connection = $this->config->get('queue.default');
        if (!is_string($connection) || $connection === '') {
            throw new InvalidConfigurationException('Queue connection must be configured.');
        }
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');
        $retry = $this->config->get('queue.connections.'.$connection.'.retry_after', $requireBroker ? null : 180);
        if ($requireBroker && (!is_string($driver) || in_array($driver, ['sync', 'null'], true))) {
            throw new InvalidConfigurationException('Scanning requires an asynchronous queue connection.');
        }
        if ($this->scanWork('scanner_timeout_seconds') >= 90 || $this->scanWork('lease_seconds') <= 90
            || ($requireBroker && (!is_int($retry) || $retry < 180 || $retry <= $this->scanWork('lease_seconds')))) {
            throw new InvalidConfigurationException('Scanner deadline, job timeout, lease and broker retry_after must be ordered.');
        }
    }

    /** @return list<class-string<FileHandler>> the FQCN list of `vaulter-drive.handlers`; entries that are no FileHandler are skipped */
    #[Internal]
    public function handlers(): array
    {
        $value = $this->config->get('vaulter-drive.handlers', []);

        $handlers = [];
        foreach (is_array($value) ? $value : [] as $class) {
            if (is_string($class) && is_a($class, FileHandler::class, allow_string: true)) {
                $handlers[] = $class;
            }
        }

        return $handlers;
    }

    /** @return list<class-string<ProfileProvider>> */
    #[Internal]
    public function profileProviders(): array
    {
        $classes = $this->config->get('vaulter-drive.profiles.providers', []);
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter-drive.profiles.providers must be a list of ProfileProvider classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !is_a($class, ProfileProvider::class, true)) {
                throw new InvalidConfigurationException('[Vaulter] Invalid class in vaulter-drive.profiles.providers.');
            }
        }

        /** @var list<class-string<ProfileProvider>> $classes */
        return $classes;
    }

    /** @return class-string<ProfileProvider> */
    #[Internal]
    public function defaultProfile(): string
    {
        $class = $this->config->get('vaulter-drive.profiles.default', DefaultProfile::class);
        if (!is_string($class) || !is_a($class, ProfileProvider::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid class for vaulter-drive.profiles.default.');
        }

        return $class;
    }

    /** The global switch of a drive feature (`vaulter-drive.features.<feature>`), before any profile narrows it. */
    #[Internal]
    public function featureFlag(string $feature): bool
    {
        return $this->config->get('vaulter-drive.features.'.$feature) === true;
    }

    /** Whether deleting the owner entity deletes its drives (HasDrives). */
    #[Internal]
    public function drivesDeleteWithOwner(): bool
    {
        return $this->config->get('vaulter-drive.drives.delete_with_owner', false) === true;
    }

    /**
     * The class of a model the host may swap (`vaulter-drive.models.<key>`), checked against the shipped model. The
     * blob model is the media package's (D6): its key lives in `vaulter-media.models.blob`.
     *
     * @throws InvalidConfigurationException when the key names no drive model or the class does not extend the shipped one
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
     *     $key is 'blob' ? class-string<Blob> : (
     *     $key is 'grant' ? class-string<NodeGrant> : (
     *     $key is 'node_share' ? class-string<NodeShare> : (
     *     $key is 'activity' ? class-string<Activity> : (
     *     $key is 'upload_session' ? class-string<UploadSession> : (
     *     $key is 'upload_part' ? class-string<UploadPart> : class-string<Model>)))))))))))))))
     * )
     */
    #[Internal]
    public function model(string $key): string
    {
        if ($key === 'blob') {
            return (new MediaConfig($this->config))->blobModel();
        }

        $defaults = [
            'scan_obligation' => ScanObligation::class,
            'scan_request' => ScanRequest::class,
            'scan_evidence' => ScanEvidenceRecord::class,
            'scan_policy_state' => ScanPolicyState::class,
            'scan_backfill_run' => ScanBackfillRun::class,
            'drive' => Drive::class,
            'node' => Node::class,
            'file' => File::class,
            'file_revision' => FileRevision::class,
            'file_revision_blob' => FileRevisionBlob::class,
            'grant' => NodeGrant::class,
            'node_share' => NodeShare::class,
            'activity' => Activity::class,
            'upload_session' => UploadSession::class,
            'upload_part' => UploadPart::class,
        ];

        $path = 'vaulter-drive.models.'.$key;
        if (!isset($defaults[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for '.$path.'.');
        }
        $class = $this->config->get($path, $defaults[$key]);
        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for '.$path.'.');
        }

        ConfigValidator::assertModel($path, $class, $defaults[$key]);

        return $class;
    }

    /** Whether a drive feature is on: globally, or for `$profile` once the profile narrows it. */
    public function featureEnabled(string $feature, ?string $profile = null): bool
    {
        if ($profile !== null) {
            return app(ProfilePolicy::class)->featureEnabled(app(ProfileRegistry::class)->get($profile), $feature);
        }

        return $this->featureFlag($feature);
    }

    public function scan(): ScanConfig
    {
        return new ScanConfig($this->config);
    }

    public function access(): AccessConfig
    {
        return new AccessConfig($this->config);
    }

    public function listing(): ListingConfig
    {
        return new ListingConfig($this->config);
    }

    public function sharing(): SharingConfig
    {
        return new SharingConfig($this->config);
    }

    public function downloads(): DownloadsConfig
    {
        return new DownloadsConfig($this->config);
    }

    public function changes(): ChangesConfig
    {
        return new ChangesConfig($this->config);
    }

    public function defaultQuota(): ?int
    {
        $value = $this->config->get('vaulter-drive.quota.default_bytes');
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidConfigurationException('Default quota must be nonnegative or null.');
        }

        return $value;
    }

    /** @return list<string> */
    public function blockedExtensions(): array
    {
        return $this->stringList($this->config->get('vaulter-drive.uploads.blocked_extensions', []), []);
    }

    public function maxFileSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.max_file_size', default: 5_368_709_120); // 5 GiB

        $bytes = is_numeric($value) ? (int) $value : 5_368_709_120;

        return max($bytes, 0);
    }

    public function chunkSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.chunk_size', default: 8_388_608); // 8 MiB

        $bytes = is_numeric($value) ? (int) $value : 8_388_608;

        return max($bytes, 1);
    }

    public function maxChunkSize(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.max_chunk_size', default: 67_108_864); // 64 MiB

        $bytes = is_numeric($value) ? (int) $value : 67_108_864;

        return max($bytes, 0);
    }

    /** The finite completion budget (D19): 1..300 s, default 60, across all DB retries of one request. */
    public function completeTimeoutSeconds(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.complete_timeout_seconds', default: 60);

        $seconds = is_numeric($value) ? (int) $value : 60;

        return min(max($seconds, 1), 300);
    }

    public function sessionTtlHours(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.session_ttl_hours', default: 24);

        $hours = is_numeric($value) ? (int) $value : 24;

        return max($hours, 1);
    }

    public function maxActiveSessionsPerActor(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.max_active_sessions_per_actor', default: 20);

        $max = is_numeric($value) ? (int) $value : 20;

        return max($max, 0);
    }

    /** @return list<string> */
    public function metadataAllowedKeys(): array
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.metadata.allowed_keys', default: ['filename', 'filetype', 'name', 'mime_type']);

        $keys = [];

        foreach (is_array($value) ? $value : [] as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function metadataMaxValueLength(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.metadata.max_value_length', default: 512);

        $length = is_numeric($value) ? (int) $value : 512;

        return max($length, 1);
    }

    public function trashRetentionDays(): int
    {
        $value = $this->config->get(key: 'vaulter-drive.trash.retention_days', default: 30);

        $days = is_numeric($value) ? (int) $value : 30;

        return max($days, 0);
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return $this->stringList($this->config->get(key: 'vaulter-drive.uploads.allowed_mime_types', default: []), []);
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    public function resumableEnabled(): bool
    {
        $value = $this->config->get(key: 'vaulter-drive.uploads.resumable.enabled', default: true);

        return (bool) $value;
    }
}
