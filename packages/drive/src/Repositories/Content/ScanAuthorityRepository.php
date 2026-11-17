<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Media\Configuration\MediaOptions;
use Vaulter\Media\Models\Cas\Blob;

/**
 * Fresh reads of the rows scan work validates against.
 *
 * Unlike DriveReadRepository and FileRevisionReadRepository these reads are neither cached nor narrowed
 * by the tenancy constraint: scan workers run outside a tenant request, so the scan Actions check tenant,
 * drive and content identity explicitly instead. Keep every such unconstrained read in this class.
 *
 * Scan Actions only.
 */
#[Internal]
final readonly class ScanAuthorityRepository
{
    public function __construct(
        private DriveOptions $options,
        private MediaOptions $mediaOptions,
    ) {}

    public function drive(int|string $id): ?Drive
    {
        $class = $this->options->model('drive');

        return $class::query()->whereKey($id)->first();
    }

    public function driveOrFail(int|string $id): Drive
    {
        $class = $this->options->model('drive');

        return $class::query()->whereKey($id)->firstOrFail();
    }

    /** Re-reads by the persisted key, so attributes changed on the passed model are never trusted. */
    public function persistedDriveOrFail(Drive $drive): Drive
    {
        $class = $this->options->model('drive');

        return $class::query()->whereKey($drive->getRawOriginal($drive->getKeyName()))->firstOrFail();
    }

    public function lockDriveOrFail(int|string $id): Drive
    {
        $class = $this->options->model('drive');

        return $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function revision(int|string $id): ?FileRevision
    {
        $class = $this->options->model('file_revision');

        return $class::query()->whereKey($id)->first();
    }

    public function revisionOrFail(int|string $id): FileRevision
    {
        $class = $this->options->model('file_revision');

        return $class::query()->whereKey($id)->firstOrFail();
    }

    public function blob(int|string $id): ?Blob
    {
        $class = $this->mediaOptions->blobModel;

        return $class::query()->whereKey($id)->first();
    }
}
