<?php

declare(strict_types=1);

namespace Vaulter\Drive\Files;

use LogicException;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Profiles\Profile;

final readonly class FileRevisionRetention
{
    public function __construct(private BlobStore $blobs, private VaulterDatabase $database) {}

    /** Caller holds the file lock after allocating and committing the new head. */
    public function prune(File $file, Profile $profile): int
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Revision pruning requires the file transaction and lock.');
        }
        $limit = $profile->getRevisionLimit();
        if ($limit === null) {
            return 0;
        }
        $revisions = $file->versions()->orderByDesc('number')->lockForUpdate()->get();
        $remaining = $limit;
        $head = (string) $file->current_revision_id;
        foreach ($revisions as $revision) {
            if ((string) $revision->id === $head && !$revision->keep_forever) {
                $remaining--;
            }
        }
        $pruned = 0;
        foreach ($revisions as $revision) {
            if ($revision->keep_forever || (string) $revision->id === $head) {
                continue;
            }
            if ($remaining-- > 0) {
                continue;
            }
            foreach ($revision->blobs()->get() as $blob) {
                /** @var FileRevisionBlob $edge */
                $edge = $blob->getRelation('pivot');
                $this->blobs->detach(version: $revision, blob: $blob, role: $edge->role, ordinal: $edge->ordinal);
            }
            $revision->delete();
            $pruned++;
        }

        return $pruned;
    }
}
