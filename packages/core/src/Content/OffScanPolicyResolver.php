<?php

declare(strict_types=1);

namespace Vaulter\Content;

use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

final readonly class OffScanPolicyResolver implements ScanPolicyResolver
{
    public function __construct(private ProfilePolicy $policy, private ProfileRegistry $profiles) {}

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
