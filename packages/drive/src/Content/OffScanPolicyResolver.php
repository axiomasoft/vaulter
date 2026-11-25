<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Internal]
final readonly class OffScanPolicyResolver implements ScanPolicyResolver
{
    public function __construct(private ProfilePolicy $policy, private ProfileRegistry $profiles) {}

    public function forDrive(Drive $drive): array
    {
        return $this->resolve($drive);
    }

    public function required(FileRevision $revision, ScanPolicySpec $spec): bool
    {
        if ($spec->mode !== ScanMode::Off) {
            throw new InvalidConfigurationException('Enabled scan policy requires the drive scan runtime.');
        }

        return false;
    }

    public function requiredSpecs(Drive $drive, FileRevision $revision): array
    {
        $this->resolve($drive);

        return [];
    }

    public function resolve(Drive $drive): array
    {
        $specs = $this->policy->scanSpecs($this->profiles->get($drive->profile));
        foreach ($specs as $spec) {
            if ($spec->mode !== ScanMode::Off) {
                throw new InvalidConfigurationException('Enabled scan policy requires the drive scan runtime.');
            }
        }

        return $specs;
    }
}
