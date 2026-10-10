<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Data\Drive\FileStream;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;

interface FileOperations
{
    public function upload(UploadSource $source, ?UploadOptions $options = null): NodeSnapshot;

    public function addRevision(NodeSnapshot|string|int $file, UploadSource $source, ?RevisionOptions $options = null): FileRevisionSnapshot;

    /** @return Page<FileRevisionSnapshot> */
    public function revisions(NodeSnapshot|string|int $file, ?ListOptions $options = null): Page;

    /**
     * Copies the revision forward as the new head (restored_from_revision_id); its bytes
     * count against the quota again. With $expectedSequence the file must still be at
     * that sequence, checked before the copy is made.
     */
    public function restoreRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, ?int $expectedSequence = null): FileRevisionSnapshot;

    /**
     * Delete a non-current revision and release its bytes (Manage). The head and pinned
     * revisions are refused.
     *
     * @throws CannotDeleteHeadRevisionException
     * @throws RevisionPinnedException
     */
    public function deleteRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision): void;

    /** Pin (keep forever) or unpin a revision; pinned revisions survive the retention policy (Edit). */
    public function setRevisionRetention(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, bool $keepForever): FileRevisionSnapshot;

    /** The caller closes the returned stream. */
    public function read(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int|null $revision = null): FileStream;
}
