<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\CopyDestinationAdmission;
use Vaulter\Drive\Content\ScanAdmission;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\NodeSequence;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\TreeGuard;

final readonly class RestoreFileRevisionAction
{
    public function __construct(private VaulterDatabase $database, private TreeGuard $tree, private NodeLocator $locator, private NodeSequence $sequence, private FileStoreRepository $files, private FileRevisionStoreRepository $revisions, private NodeStoreRepository $nodes, private DriveStoreRepository $drives, private BlobStore $blobs, private CopyDestinationAdmission $admission) {}

    public function execute(OperationContext $context, RestoreFileRevisionInput $data): FileRevision
    {
        $node = $this->locator->find($context, $data->node);

        return $this->database->transaction(function () use ($context, $data, $node): FileRevision {
            $this->database->lockTreeShared($node->drive_id);
            $node = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            $this->sequence->assertExpected($node, $data->expectedSequence);
            $file = $this->files->lockForUpdate($node->file()->firstOrFail());
            $source = $file->versions()->whereKey($data->revision->getRawOriginal($data->revision->getKeyName()))->whereIn('status', ['committed', 'superseded'])->lockForUpdate()->first() ?? throw NodeNotFoundException::forId($node->id);
            if ($source->scan_status === 'infected') {
                throw new ContentQuarantinedException;
            }
            $this->admission->assertSource($source);
            $admissionSequence = app(ScanAdmission::class)->next($context);
            $previous = $file->currentRevision()->first();
            $delta = $source->size_bytes - ($previous->size_bytes ?? 0);
            if ($delta > 0) {
                $this->drives->reserveBytes($context->drive, $delta);
            } elseif ($delta < 0) {
                $this->drives->adjustUsedBytes($context->drive, $delta);
            }
            $attributes = $source->getAttributes();
            unset($attributes[$source->getKeyName()],$attributes['created_at']);
            $attributes = [...$attributes, 'status' => 'committed', 'number' => $file->revision_count + 1, 'created_by' => $context->actor->ref()->id, 'committed_at' => now(), 'scan_status' => $this->admission->status($context), 'scan_admission_sequence' => $admissionSequence, 'restored_from_revision_id' => $source->id];
            $revision = $this->revisions->create($attributes);
            foreach ($source->blobs as $blob) {

                /** @var FileRevisionBlob $pivot */ $pivot = $blob->getRelationValue('pivot');
                $this->blobs->attach($revision, $blob, ['role' => $pivot->role, 'ordinal' => $pivot->ordinal, 'offset_bytes' => $pivot->offset_bytes, 'length_bytes' => $pivot->length_bytes]);
            }
            app(ScanAdmission::class)->admit($context, $revision, $source);
            if ($previous !== null) {
                $this->revisions->markSuperseded($previous);
            }
            $this->files->repointHead($file, $revision->id, $revision->mime_type, $revision->size_bytes, $revision->number);
            $this->nodes->cacheHead($node, $revision->mime_type, $node->extension, $revision->size_bytes, $context->actor->ref()->id);
            $this->sequence->bump($node);
            event(new FileRevisionAdded(node: $node, file: $file, version: $revision, versionNo: $revision->number, actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $revision;
        });
    }
}
