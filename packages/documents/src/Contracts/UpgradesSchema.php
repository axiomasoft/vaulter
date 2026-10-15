<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Optional: the document type can read content written in an older schema version.
 * The upgrade runs on read; stored history is never rewritten.
 */
#[Spi(SpiKind::Implement)]
interface UpgradesSchema
{
    /**
     * Raise $content from $fromVersion to schemaVersion(). Idempotent.
     *
     * @param  array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function upgrade(array $content, int $fromVersion): array;
}
