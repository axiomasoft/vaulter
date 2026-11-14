<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\CopyDestinationAdmission;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Content\ScanAdmission;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Events\Files\FileRevisionAdded;
use Vaulter\Drive\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\NodeSequence;
use Vaulter\Drive\Profiles\DrivePolicyEnforcer;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Drive\Repositories\FileRevisionStoreRepository;
use Vaulter\Drive\Repositories\FileStoreRepository;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

#[Internal]
final readonly class RestoreFileRevisionAction
{
    public function __construct(private VaulterDatabase $database, private TreeGuard $tree, private NodeLocator $locator, private NodeSequence $sequence, private FileStoreRepository $files, private FileRevisionStoreRepository $revisions, private NodeStoreRepository $nodes, private DriveStoreRepository $drives, private RevisionBlobLinks $links, private CopyDestinationAdmission $admission, private DrivePolicyEnforcer $policy, private DriveLifecycle $lifecycle, private EventRecorder $recorder, private NodeSnapshotMapper $nodeSnapshots, private FileRevisionSnapshotMapper $revisionSnapshots) {}

    public function execute(DriveContext $context, RestoreFileRevisionInput $data): FileRevision
    {
        $node = $this->locator->find($context, $data->node);

        return $this->database->transaction(function () use ($context, $data, $node): FileRevision {
            $this->database->lockAggregateShared('drives', $node->drive_id);
            $this->lifecycle->assertWritable($context);
            $node = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            $this->tree->assertLive($node);
            $this->sequence->assertExpected($node, $data->expectedSequence);
            $file = $this->files->lockForUpdate($node->file()->firstOrFail());
            $source = $file->versions()->whereKey($data->revision->getRawOriginal($data->revision->getKeyName()))->whereIn('status', ['committed', 'superseded'])->lockForUpdate()->first() ?? throw NodeNotFoundException::forId($node->id);
            // A lowered limit leaves existing files readable and shrinkable, but
            // restoring a larger payload must pass the current size admission.
            if ($source->size_bytes > $file->size_bytes) {
                $this->policy->assertFileSize($context->drive, $source->size_bytes);
            }
            if ($source->scan_status === 'infected') {
                throw new ContentQuarantinedException;
            }
            $this->admission->assertSource($source);
            $admissionSequence = app(ScanAdmission::class)->next($context);
            $previous = $file->currentRevision()->first();
            // The restored copy is a new retained revision: its full size counts.
            if ($source->size_bytes > 0) {
                $this->drives->reserveBytes($context->drive, $source->size_bytes);
            }
            $attributes = $source->getAttributes();
            unset($attributes[$source->getKeyName()], $attributes['created_at'], $attributes['superseded_at']);
            $attributes = [...$attributes, 'status' => 'committed', 'number' => $file->revision_count + 1, 'created_by' => $context->actor->ref()->id, 'committed_at' => now(), 'scan_status' => $this->admission->status($context), 'scan_admission_sequence' => $admissionSequence, 'restored_from_revision_id' => $source->id];
            $revision = $this->revisions->create($attributes);
            foreach ($source->blobs as $blob) {

                /** @var FileRevisionBlob $pivot */ $pivot = $blob->getRelationValue('pivot');
                $this->links->attach($revision, $blob, ['role' => $pivot->role, 'ordinal' => $pivot->ordinal, 'offset_bytes' => $pivot->offset_bytes, 'length_bytes' => $pivot->length_bytes]);
            }
            app(ScanAdmission::class)->admit($context, $revision, $source);
            if ($previous !== null) {
                $this->revisions->markSuperseded($previous);
            }
            $this->files->repointHead($file, $revision->id, $revision->mime_type, $revision->size_bytes, $revision->number);
            $this->nodes->cacheHead($node, $revision->mime_type, $node->extension, $revision->size_bytes, $context->actor->ref()->id);
            $this->sequence->bump($node);
            $this->recorder->record(new FileRevisionAdded(nodeSnapshots: $this->nodeSnapshots, revisionSnapshots: $this->revisionSnapshots, node: $node, file: $file, version: $revision, versionNo: $revision->number, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $revision;
        });
    }
}
