<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

interface FileOperations
{
    public function upload(UploadSource $source, ?UploadOptions $options = null): Node;

    public function addRevision(Node|int|string $file, UploadSource $source, ?RevisionOptions $options = null): FileRevision;

    /** @return CursorPaginator<int, FileRevision> */
    public function revisions(Node|int|string $file, ?ListOptions $options = null): CursorPaginator;

    /** Copies the revision forward as the new head (restored_from_revision_id); its bytes count against the quota again. */
    public function restoreRevision(Node|int|string $file, FileRevision|int|string $revision): FileRevision;

    /**
     * Delete a non-current revision and release its bytes (Manage). The head and pinned
     * revisions are refused.
     *
     * @throws CannotDeleteHeadRevisionException
     * @throws RevisionPinnedException
     */
    public function deleteRevision(Node|int|string $file, FileRevision|int|string $revision): void;

    /** Pin (keep forever) or unpin a revision; pinned revisions survive the retention policy (Edit). */
    public function setRevisionRetention(Node|int|string $file, FileRevision|int|string $revision, bool $keepForever): FileRevision;

    /** The caller closes the returned stream. */
    public function read(Node|int|string $file, FileRevision|int|string|null $revision = null): FileStream;
}
