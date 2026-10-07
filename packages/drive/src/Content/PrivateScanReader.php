<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Repositories\Content\ScanObligationRepository;

final readonly class PrivateScanReader
{
    public function __construct(private ScanObligationRepository $work, private ScanSnapshot $snapshots,
        private BlobStore $blobs) {}

    /** @return resource|null */
    public function open(ScanAttempt $attempt): mixed
    {
        $revision = $this->work->fencedAttempt($attempt);
        if ($revision === null || $attempt->blobId === null) {
            return null;
        }
        $snapshot = $this->snapshots->load($revision);
        if ($snapshot['identity']?->fingerprint() !== $attempt->contentIdentity || $snapshot['blob'] === null) {
            return null;
        }

        return $this->blobs->read($snapshot['blob']);
    }
}
