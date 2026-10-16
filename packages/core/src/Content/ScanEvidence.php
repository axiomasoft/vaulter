<?php

declare(strict_types=1);

namespace Vaulter\Content;

use DateTimeImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Media\Enums\Cas\ScanVerdict;

#[Internal]
final readonly class ScanEvidence
{
    public function __construct(public int|string $id, public int|string $revisionId, public int|string|null $blobId, public int|string $driveId, public int|string|null $tenantId, public string $scopeKey, public string $contentIdentity, public string $specKey, public ScanVerdict $verdict, public ?DateTimeImmutable $revokedAt, public DateTimeImmutable $observedAt)
    {
        if ($blobId === null && $verdict !== ScanVerdict::Failed) {
            throw new \InvalidArgumentException('Successful evidence requires an exact blob.');
        }
    }
}
