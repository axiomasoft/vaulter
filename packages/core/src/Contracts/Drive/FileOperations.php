<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

interface FileOperations
{
    public function upload(UploadSource $source, ?UploadOptions $options = null): Node;

    public function addRevision(Node|int|string $file, UploadSource $source, ?RevisionOptions $options = null): FileRevision;

    /** @return CursorPaginator<int, FileRevision> */
    public function revisions(Node|int|string $file, ?ListOptions $options = null): CursorPaginator;

    public function restoreRevision(Node|int|string $file, FileRevision|int|string $revision): FileRevision;

    /** The caller closes the returned stream. */
    public function read(Node|int|string $file, FileRevision|int|string|null $revision = null): FileStream;
}
