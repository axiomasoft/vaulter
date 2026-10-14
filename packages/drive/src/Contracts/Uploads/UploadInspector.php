<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Uploads;

use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Operations\DriveContext;
use Vaulter\Profiles\UploadPolicy;

interface UploadInspector
{
    public function key(): string;

    public function priority(): int;

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void;
}
