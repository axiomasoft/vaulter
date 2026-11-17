<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\UploadPolicy;

#[Internal]
final readonly class SizeInspector implements UploadInspector
{
    public function key(): string
    {
        return 'SizeInspector';
    }

    public function priority(): int
    {
        return 90;
    }

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void
    {
        if ($policy->maxFileSize !== null && $facts->size > $policy->maxFileSize) {
            throw FileSizeLimitExceededException::forLimit($policy->maxFileSize);
        }
    }
}
