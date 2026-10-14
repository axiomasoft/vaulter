<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Inspection;

use Vaulter\Configuration\ScanConfig;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Operations\DriveContext;
use Vaulter\Profiles\UploadPolicy;

final readonly class ScanDispatchInspector implements UploadInspector
{
    public function key(): string
    {
        return 'ScanDispatchInspector';
    }

    public function priority(): int
    {
        return 0;
    }

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void
    {
        // Admission owns durable work and publication after the outer commit.
        app(ScanConfig::class)->globalSpec();
    }
}
