<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Drive\Actions\Content\ActivateScanPolicyAction;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver as Resolver;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\Content\ScanPolicyStateRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Internal]
final readonly class ScanPolicyResolver implements Resolver
{
    public function __construct(private ProfilePolicy $profilesPolicy, private ProfileRegistry $profiles,
        private ScanPolicyStateRepository $states, private ScanSnapshot $snapshots,
        private ActivateScanPolicyAction $activation) {}

    /** @return array{ScanPolicySpec,ScanPolicySpec} */
    public function resolve(Drive $drive): array
    {
        return $this->forDrive($drive);
    }

    /** @return array{ScanPolicySpec,ScanPolicySpec} */
    public function desired(Drive $drive): array
    {
        return $this->profilesPolicy->scanSpecs($this->profiles->get($drive->profile));
    }

    /** @return array{ScanPolicySpec,ScanPolicySpec} */
    public function forDrive(Drive $drive): array
    {
        $specs = $this->desired($drive);
        foreach ($specs as $spec) {
            $state = $this->states->state($drive, $spec);
            if ($state === null && $spec->mode === ScanMode::Off) {
                continue;
            }
            if ($state === null || $state->config_fingerprint !== $spec->configFingerprint()) {
                throw new InvalidConfigurationException('Persisted scan policy activation is missing or differs from configured policy.');
            }
        }

        return $specs;
    }

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool
    {
        $snapshot = $this->snapshots->load($revision);
        $current = $this->forDrive($snapshot['drive']);
        if (!array_any($current, fn (ScanPolicySpec $candidate): bool => $candidate->predicateKey === $spec->predicateKey
            && $candidate->configFingerprint() === $spec->configFingerprint())) {
            throw new InvalidConfigurationException('Scan policy is no longer current.');
        }
        $state = $this->states->state($snapshot['drive'], $spec);

        return $spec->requiresScan($snapshot['revision']->scan_admission_sequence, $state?->boundary_sequence);
    }

    public function initialize(Drive $drive, DriveContext $context): void
    {
        if (app(TenancyConfig::class)->enabled()) {
            $drive = app(TenancyBoundary::class)->assertDrive($drive);
        }
        foreach ($this->desired($drive) as $spec) {
            $this->activation->execute($drive, $spec, $spec->rolloutKey, $context);
        }
    }
}
