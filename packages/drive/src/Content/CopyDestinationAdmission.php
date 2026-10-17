<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\DriveContext;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;

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
                return 'pending';
            }
        }

        return 'not_required';
    }
}
