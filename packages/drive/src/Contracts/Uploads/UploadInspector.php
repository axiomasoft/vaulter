<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Uploads;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Data\Files\UploadFacts;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Profiles\UploadPolicy;

#[Spi(SpiKind::Implement)]
interface UploadInspector
{
    public function key(): string;

    public function priority(): int;

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void;
}
