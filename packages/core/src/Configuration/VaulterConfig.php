<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\AccessConfig;
use Vaulter\Drive\Configuration\ChangesConfig;
use Vaulter\Drive\Configuration\DownloadsConfig;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Configuration\ListingConfig;
use Vaulter\Drive\Configuration\ScanConfig;
use Vaulter\Drive\Configuration\SharingConfig;
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
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Models\Cas\Blob;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class VaulterConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    /** @return ($key is 'scan_backfill_run' ? class-string<ScanBackfillRun> : ($key is 'scan_policy_state' ? class-string<ScanPolicyState> : ($key is 'scan_evidence' ? class-string<ScanEvidenceRecord> : ($key is 'scan_request' ? class-string<ScanRequest> : ($key is 'scan_obligation' ? class-string<ScanObligation> : ($key is 'drive' ? class-string<Drive> : ($key is 'node' ? class-string<Node> : ($key is 'file' ? class-string<File> : ($key is 'file_revision' ? class-string<FileRevision> : ($key is 'file_revision_blob' ? class-string<FileRevisionBlob> : ($key is 'blob' ? class-string<Blob> : ($key is 'grant' ? class-string<NodeGrant> : ($key is 'node_share' ? class-string<NodeShare> : ($key is 'activity' ? class-string<Activity> : ($key is 'outbox' ? class-string<OutboxEvent> : class-string<Model>))))))))))))))) */
    public function model(string $key): string
    {
        if ($key === 'blob') {
            // The blob model is the media package's (D6): its key lives in vaulter-media.models.blob.
            return (new MediaConfig($this->config))->blobModel();
        }

        // The outbox model is the kernel's; every other model is the drive's (the webhook models are read through WebhooksConfig).
        $core = [
            'outbox' => OutboxEvent::class,
        ];
        if (!isset($core[$key])) {
            return (new DriveConfig($this->config))->modelClass($key);
        }

        $path = 'vaulter.models.'.$key;
        $class = $this->config->get($path, $core[$key]);
        if (!is_string($class)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for '.$path.'.');
        }

        ConfigValidator::assertModel($path, $class, $core[$key]);

        return $class;
    }

    public function featureEnabled(string $feature, ?string $profile = null): bool
    {
        if ($profile !== null) {
            return app(ProfilePolicy::class)->featureEnabled(app(ProfileRegistry::class)->get($profile), $feature);
        }

        return (new DriveConfig($this->config))->featureFlag($feature);
    }

    public function scan(): ScanConfig
    {
        return new ScanConfig($this->config);
    }

    public function database(): DatabaseConfig
    {
        return new DatabaseConfig($this->config);
    }

    public function ids(): IdsConfig
    {
        return new IdsConfig($this->config);
    }

    public function tenancy(): TenancyConfig
    {
        return new TenancyConfig($this->config);
    }

    public function access(): AccessConfig
    {
        return new AccessConfig($this->config);
    }

    public function routes(): RoutesConfig
    {
        return new RoutesConfig($this->config);
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

    public function outbox(): OutboxConfig
    {
        return new OutboxConfig($this->config);
    }

    public function changes(): ChangesConfig
    {
        return new ChangesConfig($this->config);
    }

    public function observability(): ObservabilityConfig
    {
        return new ObservabilityConfig($this->config);
    }
}
