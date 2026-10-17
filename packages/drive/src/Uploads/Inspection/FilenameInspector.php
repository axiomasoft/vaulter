<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\UploadPolicy;

#[Internal]
final readonly class FilenameInspector implements UploadInspector
{
    public function key(): string
    {
        return 'FilenameInspector';
    }

    public function priority(): int
    {
        return 100;
    }

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void
    {
        if ($facts->name === '' || str_contains($facts->name, "\0") || in_array(strtolower($facts->extension), array_map('strtolower', $policy->blockedExtensions), true)) {
            throw new UploadRejectedException('extension_blocked');
        }
    }
}
