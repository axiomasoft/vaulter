<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

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
