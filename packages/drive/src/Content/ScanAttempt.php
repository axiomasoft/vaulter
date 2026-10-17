<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use DateTimeImmutable;
use Vaulter\Attributes\Internal;

#[Internal]
final readonly class ScanAttempt
{
    public function __construct(public int|string $obligationId, public int|string $revisionId, public int|string|null $blobId, public int|string $driveId, public int|string|null $tenantId, public string $contentIdentity, public ScanPolicySpec $spec, public int $generation, public int $attempt, public string $leaseToken, public DateTimeImmutable $leaseExpiresAt, public DateTimeImmutable $deadline) {}
}
