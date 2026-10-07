<?php

declare(strict_types=1);

namespace Vaulter\Content;

use DateTimeImmutable;

final readonly class ScanInput
{
    /** @param resource $stream */
    public function __construct(public mixed $stream, public int|string $revisionId, public int|string $blobId, public int|string $driveId, public int|string|null $tenantId, public string $scopeKey, public string $sha256, public int $size, public ?string $detectedMime, public string $scannerVersion, public string $policyVersion, public DateTimeImmutable $deadline)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || $size < 0) {
            throw new \InvalidArgumentException('Scan input requires an owned stream and nonnegative size.');
        }
    }
}
