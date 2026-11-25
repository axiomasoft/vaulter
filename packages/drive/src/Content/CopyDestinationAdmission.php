<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Media\Enums\Cas\ScanMode;

#[Internal]
final readonly class CopyDestinationAdmission
{
    public function __construct(private ProfilePolicy $policy, private ProfileRegistry $profiles, private ScanEvidenceRepository $evidence) {}

    public function assertSource(FileRevision $source): void
    {
        if ($this->evidence->infected($source)) {
            throw new ContentQuarantinedException;
        }
    }

    public function status(DriveContext $context): string
    {
        foreach ($this->policy->scanSpecs($this->profiles->get($context->drive->profile)) as $spec) {
            if ($spec->mode !== ScanMode::Off) {
                return ScanStatus::Pending->value;
            }
        }

        return ScanStatus::NotRequired->value;
    }
}
