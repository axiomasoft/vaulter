<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Media\Cas\BlobLock;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;

/** @internal Reloads authority and the immutable edge; passed attributes are never trusted. */
#[Internal]
final readonly class ScanSnapshot
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private BlobLock $lock) {}

    /** @return array{revision:FileRevision,file:File,node:Node,drive:Drive,blob:?Blob,identity:?ImmutableContentIdentity} */
    public function load(FileRevision $revision, bool $lock = false): array
    {
        $revisionClass = $this->config->model('file_revision');
        $fileClass = $this->config->model('file');
        $nodeClass = $this->config->model('node');
        $driveClass = $this->config->model('drive');
        $blobClass = $this->config->model('blob');
        $persisted = $revisionClass::query()->whereKey($revision->getRawOriginal($revision->getKeyName()))->first();
        $file = $persisted === null ? null : $fileClass::query()->whereKey($persisted->file_id)->first();
        $node = $file === null ? null : $nodeClass::query()->whereKey($file->node_id)->first();
        $drive = $node === null ? null : $driveClass::query()->whereKey($node->drive_id)->first();
        if ($persisted === null || $file === null || $node === null || $drive === null) {
            throw new LogicException('Scan content scope is unavailable.');
        }
        if ($lock) {
            $this->database->lockTreeShared($drive->id);
            $node = $nodeClass::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $file = $fileClass::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            $persisted = $revisionClass::query()->whereKey($persisted->id)->lockForUpdate()->firstOrFail();
            $drive = $driveClass::query()->whereKey($drive->id)->firstOrFail();
        }
        if ((string) $file->node_id !== (string) $node->id || (string) $persisted->file_id !== (string) $file->id || (string) $node->drive_id !== (string) $drive->id) {
            throw new LogicException('Scan content scope is inconsistent.');
        }
        foreach ([$persisted, $file, $node] as $model) {
            if ((string) $model->tenant_id !== (string) $drive->tenant_id) {
                throw new LogicException('Scan content tenant is inconsistent.');
            }
        }
        $blobId = $persisted->blob_id ?? $persisted->manifest_blob_id;
        $blob = $blobId === null ? null : $blobClass::query()->whereKey($blobId)->first();
        if ($lock && $blob !== null) {
            $this->lock->acquire($blob->scope_key, $blob->sha256);
            $blob = $blobClass::query()->whereKey($blob->id)->lockForUpdate()->first();
        }
        $identity = null;
        if ($blob !== null && $persisted->manifest_blob_id === null && !$blob->chunked && $blob->status === BlobStatus::Committed && $persisted->committed_at !== null && in_array($persisted->status, ['committed', 'superseded'], true)) {
            $edgeClass = $this->config->model('file_revision_blob');
            $edges = $edgeClass::query()->where('file_revision_id', $persisted->id)->get();
            $edge = $edges->first();
            if ($edge !== null && $edges->count() === 1 && (string) $edge->blob_id === (string) $blob->id && $edge->role === 'data' && (int) $edge->ordinal === 0 && $blob->sha256 === $persisted->sha256 && $blob->size_bytes === $persisted->size_bytes && ($blob->tenant_id === null || (string) $blob->tenant_id === (string) $drive->tenant_id) && ($blob->storage_id === null || (string) $blob->storage_id === (string) $drive->id)) {
                $identity = new ImmutableContentIdentity($blob->id, $blob->scope_key, $blob->disk, $blob->storage_key, $blob->storage_version, $persisted->sha256, $persisted->size_bytes);
            }
        }

        return ['revision' => $persisted, 'file' => $file, 'node' => $node, 'drive' => $drive, 'blob' => $blob, 'identity' => $identity];
    }
}
