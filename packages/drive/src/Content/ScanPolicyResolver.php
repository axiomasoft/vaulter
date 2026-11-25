<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Packman\Attributes\Internal;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Drive\Actions\Content\ActivateScanPolicyAction;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver as Resolver;
use Vaulter\Drive\Models\Content\ScanPolicyState;
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
        return $this->current($drive)[0];
    }

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool
    {
        $snapshot = $this->snapshots->load($revision);
        [$current, $states] = $this->current($snapshot['drive']);
        if (!array_any($current, fn (ScanPolicySpec $candidate): bool => $candidate->predicateKey === $spec->predicateKey
            && $candidate->configFingerprint() === $spec->configFingerprint())) {
            throw new InvalidConfigurationException('Scan policy is no longer current.');
        }

        return $spec->requiresScan($snapshot['revision']->scan_admission_sequence, ($states[$spec->predicateKey] ?? null)?->boundary_sequence);
    }

    public function requiredSpecs(Drive $drive, FileRevision $revision): array
    {
        [$current, $states] = $this->current($drive);

        return array_values(array_filter($current, static fn (ScanPolicySpec $spec): bool => $spec->requiresScan(
            $revision->scan_admission_sequence,
            ($states[$spec->predicateKey] ?? null)?->boundary_sequence,
        )));
    }

    /**
     * The configured specs of the drive, checked against their persisted activation, with the states that were read
     * for the check (one read of the drive row and one of its states, whatever the number of specs).
     *
     * @return array{array{ScanPolicySpec,ScanPolicySpec}, array<string, ScanPolicyState>}
     */
    private function current(Drive $drive): array
    {
        $specs = $this->desired($drive);
        $states = $this->states->statesFor($drive, $specs);
        foreach ($specs as $spec) {
            $state = $states[$spec->predicateKey] ?? null;
            if ($state === null && $spec->mode === ScanMode::Off) {
                continue;
            }
            if ($state === null || $state->config_fingerprint !== $spec->configFingerprint()) {
                throw new InvalidConfigurationException('Persisted scan policy activation is missing or differs from configured policy.');
            }
        }

        return [$specs, $states];
    }

    public function initialize(Drive $drive, DriveContext $context): void
    {
        if (app(TenancyOptions::class)->enabled) {
            $drive = app(TenancyBoundary::class)->assertDrive($drive);
        }
        foreach ($this->desired($drive) as $spec) {
            $this->activation->execute($drive, $spec, $spec->rolloutKey, $context);
        }
    }
}
