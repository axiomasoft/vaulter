<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Psr\Http\Message\StreamInterface;
use Vaulter\Data\Page;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Files\RestoreFileRevisionAction;
use Vaulter\Drive\Actions\Files\SetFileRevisionRetentionAction;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Content\ContentDelivery;
use Vaulter\Drive\Contracts\FileOperations as Contract;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Files\IngestReceipt;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Drive\Data\FileStream;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\RevisionOptions;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\NodeSort;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NotAFileException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\PurgeReceipt;
use Vaulter\Drive\Idempotency\PurgeReceiptCodec;
use Vaulter\Drive\Idempotency\RevisionReceiptCodec;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Support\KeysetCursor;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Support\Identity;

#[Api]
final readonly class FileOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private IngestFileAction $ingest, private RestoreFileRevisionAction $restore, private ContentDelivery $delivery, private VaulterDatabase $database, private TreeGuard $tree, private NameConflictResolver $names, private DeleteFileRevisionAction $deleteRevision, private SetFileRevisionRetentionAction $retention, private NodeSnapshotMapper $nodes, private FileRevisionSnapshotMapper $revisions, private OperationRunner $runner) {}

    public function upload(UploadSource $source, ?UploadOptions $options = null): NodeSnapshot
    {
        $options ??= new UploadOptions;
        $parent = $this->locator->find($this->context, $options->parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeCreate($this->context, $parent);
        if ($options->onConflict === OnNameConflict::AddRevision) {
            $name = $options->name ?? $source->name();
            $target = $this->uploadSibling($parent, $name);
            if ($target !== null) {
                $this->assertSiblingVisible($target, $name);
                if ($target->kind !== NodeKind::File) {
                    throw NodeNameConflictException::forName($target->name, $parent->id);
                }
                $target = $this->file($target, Ability::Edit);

                return $this->nodes->map($this->ingestSource('upload', $source, null, $target, null, $options->declaredMimeType, null, OnNameConflict::Fail, $options->clientModifiedAt)->node, $this->context->actor->ref());
            }
        }

        return $this->nodes->map($this->ingestSource('upload', $source, $parent, null, $options->name, $options->declaredMimeType, null, $options->onConflict, $options->clientModifiedAt)->node, $this->context->actor->ref());
    }

    public function addRevision(NodeSnapshot|string|int $file, UploadSource $source, ?RevisionOptions $options = null): FileRevisionSnapshot
    {
        $node = $this->file($file, Ability::Edit);
        $options ??= new RevisionOptions;
        $receipt = $this->ingestSource('add_revision', $source, null, $node, null, $options->declaredMimeType, $options->expectedSequence, OnNameConflict::Fail);

        return $this->revisions->map($this->revision($receipt->node, $receipt->revision), $receipt->node->file()->firstOrFail());
    }

    /** @return Page<FileRevisionSnapshot> */
    public function revisions(NodeSnapshot|string|int $file, ?ListOptions $options = null): Page
    {
        $node = $this->file($file, Ability::View);
        $options ??= new ListOptions;
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Revision lists support perPage and cursor only.');
        }

        $aggregate = $node->file()->firstOrFail();
        $versions = $aggregate->versions();
        $cursor = KeysetCursor::revision($options->cursor);
        $page = $versions->orderByDesc('number')->cursorPaginate(perPage: $options->perPage, cursor: $cursor);

        return new Page(
            items: array_values($page->getCollection()->map(fn (FileRevision $revision): FileRevisionSnapshot => $this->revisions->map($revision, $aggregate))->all()),
            nextCursor: $page->nextCursor()?->encode(),
        );
    }

    public function restoreRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, ?int $expectedSequence = null): FileRevisionSnapshot
    {
        $fileId = $file instanceof NodeSnapshot ? $file->id : (string) $file;
        // Idempotent by key: the receipt is the new head revision; a replay needs Edit on the file where it is now.
        $idempotency = new IdempotentWrite(
            codec: new RevisionReceiptCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Edit, $fileId),
            fingerprint: ['file' => $fileId, 'revision' => $revision instanceof FileRevisionSnapshot ? $revision->id : (string) $revision, 'expected_sequence' => $expectedSequence],
        );

        return $this->write('restore_revision', function () use ($file, $revision, $expectedSequence): FileRevisionSnapshot {
            $node = $this->file($file, Ability::Edit);

            return $this->revisions->map($this->restore->execute($this->context, new RestoreFileRevisionInput($node, $this->revision($node, $revision), $expectedSequence)), $node->file()->firstOrFail());
        }, $idempotency);
    }

    public function deleteRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision): void
    {
        $fileId = $file instanceof NodeSnapshot ? $file->id : (string) $file;
        // Idempotent by key: the receipt keeps the revision id and the file it hung under (ID2); a replay checks
        // Manage on that file, because the revision itself is gone.
        $idempotency = new IdempotentWrite(
            codec: new PurgeReceiptCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Manage, $fileId, OperationKind::Purge),
            fingerprint: ['file' => $fileId, 'revision' => $revision instanceof FileRevisionSnapshot ? $revision->id : (string) $revision],
        );

        $this->write('delete_revision', function () use ($file, $revision): PurgeReceipt {
            // Permanent removal of stored data: authorized like a purge, without the
            // upload/egress profile gates.
            $node = $this->file($file, Ability::Manage, OperationKind::Purge);
            $target = $this->revision($node, $revision);
            $receipt = new PurgeReceipt(kind: PurgeReceipt::REVISION, id: Identity::stringify($target->getKey()), driveId: (string) $this->context->driveId(), scopeNodeId: (string) $node->id, ability: Ability::Manage->value);
            $this->deleteRevision->execute($this->context, $node, $target);

            return $receipt;
        }, $idempotency);
    }

    public function setRevisionRetention(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, bool $keepForever): FileRevisionSnapshot
    {
        return $this->write('set_revision_retention', function () use ($file, $revision, $keepForever): FileRevisionSnapshot {
            $node = $this->file($file, Ability::Edit);

            return $this->revisions->map($this->retention->execute($this->context, $node, $this->revision($node, $revision), $keepForever), $node->file()->firstOrFail());
        });
    }

    public function read(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int|null $revision = null): FileStream
    {
        $node = $this->file($file, Ability::Download);
        $revision = $this->revision($node, $revision);

        return $this->delivery->stream($this->context, $node, $revision);
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, ?IdempotentWrite $idempotency = null): mixed
    {
        return $this->runner->write(new OperationSpec('drive.files.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    private function file(Node|NodeSnapshot|int|string $key, Ability $ability, ?OperationKind $operation = null): Node
    {
        $node = $this->locator->find($this->context, $key);
        $this->guard->authorize($this->context, $ability, $node, $operation ?? ($ability === Ability::Download ? OperationKind::ContentEgress : ($ability === Ability::Edit ? OperationKind::Mutation : OperationKind::Metadata)));
        if ($node->kind !== NodeKind::File || $node->file()->first() === null) {
            throw new NotAFileException;
        }

        return $node;
    }

    private function revision(Node $node, FileRevision|FileRevisionSnapshot|int|string|null $key): FileRevision
    {
        $file = $node->file()->firstOrFail();
        $id = $key instanceof FileRevision ? $key->getRawOriginal($key->getKeyName()) : ($key instanceof FileRevisionSnapshot ? $key->id : ($key ?? $file->current_revision_id));
        $versions = $file->versions();
        // A key the revision id cannot hold names no row; PostgreSQL would fail the cast.
        if ((!is_int($id) && !is_string($id)) || $id === '' || !Identity::fitsKey($versions->getModel(), $id)) {
            throw NodeNotFoundException::forId($node->id);
        }

        return $versions->whereKey($id)->whereIn('status', ['committed', 'superseded'])->first() ?? throw NodeNotFoundException::forId($node->id);
    }

    /**
     * A sibling the actor cannot View answers 404 about the requested name, never its id or kind
     * the name-conflict policy discloses occupancy, nothing more.
     */
    private function assertSiblingVisible(Node $sibling, string $name): void
    {
        if ($this->guard->visible($this->context, [$sibling]) === []) {
            throw NodeNotFoundException::forId($name);
        }
    }

    private function uploadSibling(Node $parent, string $name): ?Node
    {
        return $this->database->transaction(function () use ($parent, $name): ?Node {
            $this->database->lockAggregateShared('drives', $this->context->driveId());
            $locked = $this->tree->locked($this->tree->lock([$parent->id]), $parent->id);
            $this->tree->assertActiveFolder($locked);

            return $this->names->sibling($this->context->drive, $locked, $name);
        });
    }

    /**
     * The content is staged and inspected before the write, so a repeated attempt never
     * reads the bytes again; only the commit runs as the operation.
     */
    private function ingestSource(string $verb, UploadSource $source, ?Node $parent, ?Node $target, ?string $name, ?string $mime, ?int $expected, OnNameConflict $conflict, ?DateTimeInterface $clientModifiedAt = null): IngestReceipt
    {
        $reader = $source->openForReading();
        $prepared = null;
        try {
            if (!is_resource($reader) && !$reader instanceof StreamInterface) {
                throw new InvalidArgumentException('Upload source must provide a byte stream.');
            }
            $input = new IngestFileInput(storage: $this->context->drive, stream: $reader, originalName: $source->name(), mimeType: $mime ?? $source->declaredMimeType(), into: $parent, name: $name, targetNode: $target, expectedSequence: $expected, onConflict: $conflict, clientModifiedAt: $clientModifiedAt);
            $prepared = $this->ingest->prepare($this->context, $input);
            $input = new IngestFileInput(storage: $this->context->drive, stream: $reader, originalName: $source->name(), mimeType: $mime ?? $source->declaredMimeType(), into: $parent, name: $name, targetNode: $target, expectedSequence: $expected, onConflict: $conflict, prepared: $prepared, clientModifiedAt: $clientModifiedAt);

            // One logical call is one operation: the fallback to a new revision of the name's current
            // owner runs inside the same attempt, never as a second write(). The actor is authorized
            // again here, in the attempt: the check before staging only spares denied callers the
            // bytes, and a grant revoked while the content was staged must not commit.
            return $this->write($verb, function () use ($parent, $target, $input, $conflict, $source, $name, $mime, $reader, $prepared, $clientModifiedAt): IngestReceipt {
                if ($parent !== null) {
                    $this->guard->authorizeCreate($this->context, $this->locator->find($this->context, $parent->id));
                }
                if ($target !== null) {
                    $this->file($target->id, Ability::Edit);
                }

                try {
                    return $this->ingest->receipt($this->context, $input);
                } catch (NodeNameConflictException $exception) {
                    if ($conflict !== OnNameConflict::AddRevision || $parent === null) {
                        throw $exception;
                    }
                    $sibling = $this->uploadSibling($parent, $name ?? $source->name());
                    if ($sibling !== null) {
                        $this->assertSiblingVisible($sibling, $name ?? $source->name());
                    }
                    if ($sibling === null || $sibling->kind !== NodeKind::File) {
                        throw $exception;
                    }
                    $revision = new IngestFileInput(storage: $this->context->drive, stream: $reader, originalName: $source->name(), mimeType: $mime ?? $source->declaredMimeType(), targetNode: $this->file($sibling, Ability::Edit), prepared: $prepared, clientModifiedAt: $clientModifiedAt);

                    return $this->ingest->receipt($this->context, $revision);
                }
            });
        } finally {
            $prepared?->discard();
            $source->releaseIfOwned($reader);
        }
    }
}
