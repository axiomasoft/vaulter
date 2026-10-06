<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

final readonly class ProfilePolicy
{
    public function __construct(private VaulterConfig $config, private Repository $repository, private ProfileRegistry $profiles, private ScanConfig $scan) {}

    public function featureEnabled(Profile $profile, string $feature): bool
    {
        $global = $feature === 'comments'
            ? (bool) $this->repository->get('vaulter-documents.comments.enabled', false)
            : $this->config->featureEnabled($feature);

        return $global && ($profile->featureFlag($feature) ?? true);
    }

    public function validate(Profile $profile): void
    {
        foreach (['grants', 'share_links', 'comments'] as $feature) {
            if ($profile->featureFlag($feature) === true && !$this->featureEnabled($profile, $feature)) {
                throw new InvalidConfigurationException('Profile '.$profile->getKey().' cannot enable globally disabled '.$feature.'.');
            }
        }
        if ($profile->getDisk() !== null && !is_array($this->repository->get('filesystems.disks.'.$profile->getDisk()))) {
            throw new InvalidConfigurationException('Unknown profile disk '.$profile->getDisk().'.');
        }
        if ($profile->getPermissionDriver() !== null && !isset($this->config->access()->permissionDrivers()[$profile->getPermissionDriver()])) {
            throw new InvalidConfigurationException('Unknown profile permission driver.');
        }
        $this->scanSpecs($profile);
    }

    public function ownerAllowed(Profile $profile, Model $owner): bool
    {
        foreach ($profile->getOwners() as $class) {
            if ($owner instanceof $class) {
                return true;
            }
        }

        return $profile->getOwners() === [];
    }

    public function assertOwner(Profile $profile, Model $owner): void
    {
        if (!$owner->exists || $owner->getKey() === null || !$this->ownerAllowed($profile, $owner)) {
            throw ProfileRestrictionException::forOperation('owner');
        }
    }

    public function allows(Drive $drive, Node $node, Ability $ability, OperationKind $operation): bool
    {
        $profile = $this->profiles->get($drive->profile);
        $ownerClass = Relation::getMorphedModel($drive->owner_type) ?? $drive->owner_type;
        if ($profile->getOwners() !== [] && !array_any($profile->getOwners(), static fn (string $class): bool => is_a($ownerClass, $class, true))) {
            return false;
        }
        // Purge is a data maintenance operation; it must not depend on upload/egress policy.
        if ($operation === OperationKind::Purge) {
            return true;
        }

        return match ($ability) {
            Ability::Share => $this->featureEnabled($profile, 'share_links'),
            Ability::Comment => $this->featureEnabled($profile, 'comments'),
            Ability::Manage => $this->featureEnabled($profile, 'grants'),
            default => true,
        };
    }

    public function assertDocumentType(Drive $drive, string $type): void
    {
        $types = $this->profiles->get($drive->profile)->getDocumentTypes();
        if ($types !== [] && !in_array($type, $types, true)) {
            throw ProfileRestrictionException::forOperation('document_type');
        }
    }

    public function assertComments(Drive $drive): void
    {
        if (!$this->featureEnabled($this->profiles->get($drive->profile), 'comments')) {
            throw ProfileRestrictionException::forOperation('comments');
        }
    }

    /** @return array{ScanPolicySpec, ScanPolicySpec} */
    public function scanSpecs(Profile $profile): array
    {
        $global = $this->scan->globalSpec();
        $policy = $profile->getUploadPolicy();
        $mode = $policy->requireScan === true ? ScanMode::AllContent
            : ($policy->scanMode ?? ($policy->requireScan === false ? ScanMode::Off : $global->mode));

        return [$global, new ScanPolicySpec(
            predicateKey: 'profile:'.$profile->getKey(), mode: $mode,
            scannerKey: $global->scannerKey, scannerVersion: $global->scannerVersion,
            policyVersion: $policy->scanPolicyVersion ?? $global->policyVersion, rolloutKey: $global->rolloutKey,
        )];
    }
}
