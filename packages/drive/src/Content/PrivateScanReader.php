<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Actions\Content\FenceScanAttemptAction;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Support\Identity;

#[Internal]
final readonly class PrivateScanReader
{
    public function __construct(private FenceScanAttemptAction $fence, private ScanSnapshot $snapshots,
        private BlobStore $blobs) {}

    /** @return resource|null */
    public function open(ScanAttempt $attempt): mixed
    {
        app(TenancyBoundary::class)->assertJobDrive($attempt->driveId, $attempt->tenantId);
        $revision = $this->fence->execute($attempt);
        if ($revision === null || $attempt->blobId === null) {
            return null;
        }
        $snapshot = $this->snapshots->load($revision);
        if ($snapshot['identity']?->fingerprint() !== $attempt->contentIdentity || $snapshot['blob'] === null) {
            return null;
        }

        return $this->blobs->read(Identity::stringify($snapshot['blob']->getKey()));
    }
}
