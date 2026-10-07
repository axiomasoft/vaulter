<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\UploadPolicy;

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

    public function inspect(UploadFacts $facts, UploadPolicy $policy, OperationContext $context): void
    {
        if ($policy->maxFileSize !== null && $facts->size > $policy->maxFileSize) {
            throw new UploadRejectedException('file_too_large');
        }
    }
}
