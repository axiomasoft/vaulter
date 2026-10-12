<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Vaulter\Access\OperationGuard;
use Vaulter\Content\ContentDelivery;
use Vaulter\Contracts\Drive\FileOperations as Contract;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\DeleteFileRevisionAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Files\RestoreFileRevisionAction;
use Vaulter\Drive\Actions\Files\SetFileRevisionRetentionAction;
use Vaulter\Drive\Actions\Nodes\NameConflictResolver;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Files\IngestReceipt;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\NodeSort;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NotAFileException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\TreeGuard;

final readonly class FileOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private IngestFileAction $ingest, private RestoreFileRevisionAction $restore, private ContentDelivery $delivery, private VaulterDatabase $database, private TreeGuard $tree, private NameConflictResolver $names, private DeleteFileRevisionAction $deleteRevision, private SetFileRevisionRetentionAction $retention, private NodeSnapshotMapper $nodes, private FileRevisionSnapshotMapper $revisions) {}

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

                return $this->nodes->map($this->ingestSource($source, null, $target, null, $options->declaredMimeType, null, OnNameConflict::Fail, $options->clientModifiedAt)->node, $this->context->actor->ref());
            }
        }

        return $this->nodes->map($this->ingestSource($source, $parent, null, $options->name, $options->declaredMimeType, null, $options->onConflict, $options->clientModifiedAt)->node, $this->context->actor->ref());
    }

    public function addRevision(NodeSnapshot|string|int $file, UploadSource $source, ?RevisionOptions $options = null): FileRevisionSnapshot
    {
        $node = $this->file($file, Ability::Edit);
        $options ??= new RevisionOptions;
        $receipt = $this->ingestSource($source, null, $node, null, $options->declaredMimeType, $options->expectedSequence, OnNameConflict::Fail);

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
        $page = $aggregate->versions()->orderByDesc('number')->orderByDesc('id')->cursorPaginate(perPage: $options->perPage, cursor: $options->cursor);

        return new Page(
            items: array_values($page->getCollection()->map(fn (FileRevision $revision): FileRevisionSnapshot => $this->revisions->map($revision, $aggregate))->all()),
            nextCursor: $page->nextCursor()?->encode(),
        );
    }

    public function restoreRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, ?int $expectedSequence = null): FileRevisionSnapshot
    {
        $node = $this->file($file, Ability::Edit);

        return $this->revisions->map($this->restore->execute($this->context, new RestoreFileRevisionInput($node, $this->revision($node, $revision), $expectedSequence)), $node->file()->firstOrFail());
    }

    public function deleteRevision(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision): void
    {
        // Permanent removal of stored data: authorized like a purge, without the
        // upload/egress profile gates.
        $node = $this->file($file, Ability::Manage, OperationKind::Purge);
        $this->deleteRevision->execute($this->context, $node, $this->revision($node, $revision));
    }

    public function setRevisionRetention(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int $revision, bool $keepForever): FileRevisionSnapshot
    {
        $node = $this->file($file, Ability::Edit);

        return $this->revisions->map($this->retention->execute($this->context, $node, $this->revision($node, $revision), $keepForever), $node->file()->firstOrFail());
    }

    public function read(NodeSnapshot|string|int $file, FileRevisionSnapshot|string|int|null $revision = null): FileStream
    {
        $node = $this->file($file, Ability::Download);
        $revision = $this->revision($node, $revision);

        return $this->delivery->stream($this->context, $node, $revision);
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

        return $file->versions()->whereKey($id)->whereIn('status', ['committed', 'superseded'])->first() ?? throw NodeNotFoundException::forId($node->id);
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
            $this->database->lockTreeShared($this->context->driveId());
            $locked = $this->tree->locked($this->tree->lock([$parent->id]), $parent->id);
            $this->tree->assertActiveFolder($locked);

            return $this->names->sibling($this->context->drive, $locked, $name);
        });
    }

    private function ingestSource(UploadSource $source, ?Node $parent, ?Node $target, ?string $name, ?string $mime, ?int $expected, OnNameConflict $conflict, ?\DateTimeInterface $clientModifiedAt = null): IngestReceipt
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
                $target = $this->file($sibling, Ability::Edit);

                return $this->ingest->receipt($this->context, new IngestFileInput(storage: $this->context->drive, stream: $reader, originalName: $source->name(), mimeType: $mime ?? $source->declaredMimeType(), targetNode: $target, prepared: $prepared, clientModifiedAt: $clientModifiedAt));
            }
        } finally {
            $prepared?->discard();
            $source->releaseIfOwned($reader);
        }
    }
}
