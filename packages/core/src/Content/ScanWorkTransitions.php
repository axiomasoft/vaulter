<?php

declare(strict_types=1);

namespace Vaulter\Content;

use DateTimeImmutable;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Contracts\Content\ScanWorkSettings;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Repositories\Content\ScanObligationRepository;

/**
 * Fence, counter and terminal-state rules shared by the scan work Actions.
 *
 * Opens no transaction and takes no lock; every caller already holds the work row.
 */
#[Internal]
final readonly class ScanWorkTransitions
{
    public function __construct(private ScanObligationRepository $work, private ScanEvidenceRepository $evidence, private ScanPolicyResolver $policies, private ScanWorkSettings $settings) {}

    public function terminal(ScanObligation $work, string $reason, DateTimeImmutable $now): void
    {
        if ($work->attempt === 0) {
            $work->forceFill(['attempt' => 1])->save();
        }
        $request = $this->work->requestFor($work);
        if (!$this->evidence->hasAttempt($request, $work->attempt)) {
            $this->append($work, new ScanResult(ScanVerdict::Failed, $reason), $now);
        }
        $work->forceFill(['state' => 'settled', 'outcome' => 'failed', 'reason' => $reason, 'retryable' => false,
            'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();
    }

    public function append(ScanObligation $work, ScanResult $result, DateTimeImmutable $now): void
    {
        $this->evidence->append($work, $this->work->requestFor($work), $result, $now);
    }

    public function fence(ScanObligation $work, ScanAttempt $attempt, DateTimeImmutable $now): bool
    {
        return $work->state === 'leased' && $work->generation === $attempt->generation && $work->attempt === $attempt->attempt
            && $work->lease_token === $attempt->leaseToken && $work->lease_expires_at > $now
            && (string) $work->revision_id === (string) $attempt->revisionId && (string) $work->blob_id === (string) $attempt->blobId
            && (string) $work->drive_id === (string) $attempt->driveId && (string) $work->tenant_id === (string) $attempt->tenantId
            && $work->content_identity === $attempt->contentIdentity && $work->spec_key === $attempt->spec->specKey();
    }

    /** @param array{revision:FileRevision,file:File,node:Node,drive:Drive,blob:?Blob,identity:?ImmutableContentIdentity} $snapshot */
    public function snapshotMatches(array $snapshot, ScanObligation $work): bool
    {
        return (string) $snapshot['drive']->id === (string) $work->drive_id
            && (string) $snapshot['drive']->tenant_id === (string) $work->tenant_id
            && $snapshot['identity'] !== null && $snapshot['identity']->fingerprint() === $work->content_identity
            && $snapshot['identity']->sha256 === $work->sha256 && $snapshot['identity']->size === $work->size_bytes;
    }

    public function currentSpec(Drive $drive, string $specKey): ScanPolicySpec
    {
        foreach ($this->policies->forDrive($drive) as $spec) {
            if ($spec->specKey() === $specKey) {
                return $spec;
            }
        }
        throw new LogicException('Scan engine policy is no longer current.');
    }

    public function increment(int $value): int
    {
        if ($value < 0 || $value === PHP_INT_MAX) {
            throw new LogicException('Scan counter is exhausted.');
        }

        return $value + 1;
    }

    public function maxAttempts(): int
    {
        return $this->settings->maxAttempts();
    }
}
