<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Drive\NodeContentLifecycle;
use Vaulter\Data\Drive\CopyResult;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;

#[Internal]
final readonly class FileContentLifecycle implements NodeContentLifecycle
{
    public function __construct(
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $revisions,
        private BlobStore $blobs,
        private CopyDestinationAdmission $admission,
        private ScanAdmission $scans,
        private ContentAvailabilityGuard $availability,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::File;
    }

    /** @param iterable<array-key, Node> $sources */
    public function prepareCopy(iterable $sources): void
    {
        $pairs = $this->lockFiles($sources);
        usort($pairs, fn (array $left, array $right): int => $left[1]->current_revision_id <=> $right[1]->current_revision_id);
        foreach ($pairs as [$source, $file]) {
            $head = $file->currentRevision()->lockForUpdate()->first();
            if (!$head instanceof FileRevision || $head->status !== 'committed') {
                throw new LogicException('File node has no committed head.');
            }
            if ((string) $head->file_id !== (string) $file->id
                || $source->size_bytes !== $head->size_bytes
                || $file->size_bytes !== $head->size_bytes) {
                throw new LogicException('File node payload pointers are inconsistent.');
            }
            $this->admission->assertSource($head);
        }
    }

    /**
     * Lock the files (F) and every revision (R) of the file nodes about to be purged and
     * return the bytes they hold against the quota: all retained revisions, not only
     * the heads.
     *
     * @param iterable<array-key, Node> $sources
     */
    public function preparePurge(iterable $sources): int
    {
        $pairs = $this->lockFiles($sources);
        if ($pairs === []) {
            return 0;
        }
        $ids = array_map(fn (array $pair): int|string => $pair[1]->id, $pairs);
        $bytes = 0;
        foreach ($pairs[0][1]->versions()->getModel()->newQuery()->whereIn('file_id', $ids)->orderBy('id')->lockForUpdate()->get() as $revision) {
            $bytes += $revision->size_bytes;
        }

        return $bytes;
    }

    /** @param iterable<array-key, Node> $sources
     * @return list<array{Node, File}>
     */
    private function lockFiles(iterable $sources): array
    {
        $sources = collect($sources)->filter(fn (Node $node): bool => $node->kind === NodeKind::File)->sortBy('id');
        $pairs = [];
        foreach ($sources as $source) {
            $file = $source->file()->lockForUpdate()->first();
            if (!$file instanceof File || (string) $file->node_id !== (string) $source->id
                || (string) $file->tenant_id !== (string) $source->tenant_id) {
                throw new LogicException('File node has no consistent payload.');
            }
            $pairs[] = [$source, $file];
        }

        return $pairs;
    }

    public function copy(DriveContext $context, Node $source, Node $copy): CopyResult
    {
        $sourceFile = $source->file()->first();
        $head = $sourceFile?->currentRevision()->first();
        if (!$sourceFile instanceof File || !$head instanceof FileRevision) {
            throw new LogicException('File node has no committed payload.');
        }
        $this->availability->assertAvailable($context, $source, $head);
        $file = $this->files->create([
            'node_id' => $copy->id,
            'tenant_id' => $sourceFile->tenant_id,
            'mime_type' => $sourceFile->mime_type,
            'size_bytes' => $sourceFile->size_bytes,
            'revision_count' => 0,
            'created_by' => $context->actor->ref()->id,
        ]);
        $sequence = $this->scans->next($context);
        $revision = $this->revisions->create([
            'scan_admission_sequence' => $sequence,
            'file_id' => $file->id,
            'tenant_id' => $head->tenant_id,
            'number' => 1,
            'blob_id' => $head->blob_id,
            'manifest_blob_id' => $head->manifest_blob_id,
            'sha256' => $head->sha256,
            'size_bytes' => $head->size_bytes,
            'mime_type' => $head->mime_type,
            'declared_mime_type' => $head->declared_mime_type,
            'original_name' => $head->original_name,
            'keep_forever' => $head->keep_forever,
            'scan_status' => $this->admission->status($context),
            'status' => 'committed',
            'created_by' => $context->actor->ref()->id,
            'committed_at' => now(),
        ]);
        foreach ($head->blobs as $blob) {
            /** @var FileRevisionBlob $edge */
            $edge = $blob->getRelationValue('pivot');
            $this->blobs->attach(version: $revision, blob: $blob, attributes: [
                'role' => $edge->role,
                'ordinal' => $edge->ordinal,
                'offset_bytes' => $edge->offset_bytes,
                'length_bytes' => $edge->length_bytes,
            ]);
        }
        $this->files->repointHead(file: $file, currentVersionId: $revision->id,
            mimeType: $sourceFile->mime_type, sizeBytes: $sourceFile->size_bytes, versionNo: 1);
        $this->scans->admit($context, $revision, $head);

        return new CopyResult(addedBytes: $copy->size_bytes);
    }

    public function purge(DriveContext $context, Node $node): void
    {
        $file = $node->file()->first();
        if (!$file instanceof File) {
            return;
        }
        foreach ($file->versions()->orderBy('id')->get() as $revision) {
            foreach ($revision->blobs as $blob) {
                /** @var FileRevisionBlob $edge */
                $edge = $blob->getRelationValue('pivot');
                $this->blobs->detach(version: $revision, blob: $blob, role: $edge->role, ordinal: $edge->ordinal);
            }
        }
        $this->files->releaseHead($file);
    }
}
