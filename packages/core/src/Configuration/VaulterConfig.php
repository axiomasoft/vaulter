<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Content\ScanBackfillRun;
use Vaulter\Models\Content\ScanEvidenceRecord;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Content\ScanPolicyState;
use Vaulter\Models\Content\ScanRequest;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Profiles\DefaultProfile;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileProvider;
use Vaulter\Profiles\ProfileRegistry;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class VaulterConfig
{
    public function __construct(private Repository $config) {}

    /** @return list<class-string<FileHandler>> */
    public function handlers(): array
    {
        $value = $this->config->get('vaulter.handlers', []);

        if (!is_array($value)) {
            return [];
        }

        $handlers = [];

        foreach ($value as $class) {
            if (is_string($class) && is_a($class, FileHandler::class, allow_string: true)) {
                $handlers[] = $class;
            }
        }

        /** @var list<class-string<FileHandler>> $handlers */
        return $handlers;
    }

    /** @return ($key is 'scan_backfill_run' ? class-string<ScanBackfillRun> : ($key is 'scan_policy_state' ? class-string<ScanPolicyState> : ($key is 'scan_evidence' ? class-string<ScanEvidenceRecord> : ($key is 'scan_request' ? class-string<ScanRequest> : ($key is 'scan_obligation' ? class-string<ScanObligation> : ($key is 'drive' ? class-string<Drive> : ($key is 'node' ? class-string<Node> : ($key is 'file' ? class-string<File> : ($key is 'file_revision' ? class-string<FileRevision> : ($key is 'file_revision_blob' ? class-string<FileRevisionBlob> : ($key is 'blob' ? class-string<Blob> : ($key is 'grant' ? class-string<NodeGrant> : ($key is 'node_share' ? class-string<NodeShare> : ($key is 'activity' ? class-string<Activity> : ($key is 'outbox' ? class-string<Outbox> : ($key is 'webhook_endpoint' ? class-string<WebhookEndpoint> : ($key is 'webhook_delivery' ? class-string<WebhookDelivery> : class-string<Model>))))))))))))))))) */
    public function model(string $key): string
    {
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
            'blob' => Blob::class,
            'file_revision_blob' => FileRevisionBlob::class,
            'grant' => NodeGrant::class,
            'node_share' => NodeShare::class,
            'activity' => Activity::class,
            'outbox' => Outbox::class,
            'webhook_endpoint' => WebhookEndpoint::class,
            'webhook_delivery' => WebhookDelivery::class,
        ];

        $class = $this->config->get('vaulter.models.'.$key, $defaults[$key] ?? null);
        if (!is_string($class) || !isset($defaults[$key])) {
            throw new InvalidConfigurationException('[Vaulter] Invalid model class for vaulter.models.'.$key.'.');
        }

        ConfigValidator::assertModel('vaulter.models.'.$key, $class, $defaults[$key]);

        return $class;
    }

    /** @return list<class-string<ProfileProvider>> */
    public function profileProviders(): array
    {
        $classes = $this->config->get('vaulter.profiles.providers', []);
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.profiles.providers must be a list of ProfileProvider classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !is_a($class, ProfileProvider::class, true)) {
                throw new InvalidConfigurationException('[Vaulter] Invalid class in vaulter.profiles.providers.');
            }
        }

        /** @var list<class-string<ProfileProvider>> $classes */
        return $classes;
    }

    /** @return class-string<ProfileProvider> */
    public function defaultProfile(): string
    {
        $class = $this->config->get('vaulter.profiles.default', DefaultProfile::class);
        if (!is_string($class) || !is_a($class, ProfileProvider::class, true)) {
            throw new InvalidConfigurationException('[Vaulter] Invalid class for vaulter.profiles.default.');
        }

        return $class;
    }

    public function featureEnabled(string $feature, ?string $profile = null): bool
    {
        if ($profile !== null) {
            return app(ProfilePolicy::class)->featureEnabled(app(ProfileRegistry::class)->get($profile), $feature);
        }

        $value = $this->config->get('vaulter.features.'.$feature);

        return is_bool($value) && $value;
    }

    /** Whether deleting the owner entity deletes its drives (HasDrives). */
    public function drivesDeleteWithOwner(): bool
    {
        return $this->config->get('vaulter.drives.delete_with_owner', false) === true;
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

    public function webhooks(): WebhooksConfig
    {
        return new WebhooksConfig($this->config);
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
