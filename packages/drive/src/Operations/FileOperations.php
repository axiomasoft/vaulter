<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Pagination\CursorPaginator;
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
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Files\RestoreFileRevisionAction;
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
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private IngestFileAction $ingest, private RestoreFileRevisionAction $restore, private ContentDelivery $delivery, private VaulterDatabase $database, private TreeGuard $tree, private NameConflictResolver $names) {}

    public function upload(UploadSource $source, ?UploadOptions $options = null): Node
    {
        $options ??= new UploadOptions;
        $parent = $this->locator->find($this->context, $options->parent ?? $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeCreate($this->context, $parent);
        if ($options->onConflict === OnNameConflict::AddRevision) {
            $target = $this->uploadSibling($parent, $options->name ?? $source->name());
            if ($target !== null) {
                if ($target->kind !== NodeKind::File) {
                    throw NodeNameConflictException::forName($target->name, $parent->id);
                }
                $target = $this->file($target, Ability::Edit);

                return $this->ingestSource($source, null, $target, null, $options->declaredMimeType, null, OnNameConflict::Fail, $options->clientModifiedAt)->node;
            }
        }

        return $this->ingestSource($source, $parent, null, $options->name, $options->declaredMimeType, null, $options->onConflict, $options->clientModifiedAt)->node;
    }

    public function addRevision(Node|int|string $file, UploadSource $source, ?RevisionOptions $options = null): FileRevision
    {
        $node = $this->file($file, Ability::Edit);
        $options ??= new RevisionOptions;
        $receipt = $this->ingestSource($source, null, $node, null, $options->declaredMimeType, $options->expectedSequence, OnNameConflict::Fail);

        return $this->revision($receipt->node, $receipt->revision);
    }

    /** @return CursorPaginator<int, FileRevision> */
    public function revisions(Node|int|string $file, ?ListOptions $options = null): CursorPaginator
    {
        $node = $this->file($file, Ability::View);
        $options ??= new ListOptions;
        if ($options->sort !== NodeSort::Default || $options->descending || $options->kinds !== []) {
            throw new InvalidArgumentException('Revision lists support perPage and cursor only.');
        }

        return $node->file()->firstOrFail()->versions()->orderByDesc('number')->orderByDesc('id')->cursorPaginate(perPage: $options->perPage, cursor: $options->cursor);
    }

    public function restoreRevision(Node|int|string $file, FileRevision|int|string $revision): FileRevision
    {
        $node = $this->file($file, Ability::Edit);

        return $this->restore->execute($this->context, new RestoreFileRevisionInput($node, $this->revision($node, $revision)));
    }

    public function read(Node|int|string $file, FileRevision|int|string|null $revision = null): FileStream
    {
        $node = $this->file($file, Ability::Download);
        $revision = $this->revision($node, $revision);

        return $this->delivery->stream($this->context, $node, $revision);
    }

    private function file(Node|int|string $key, Ability $ability): Node
    {
        $node = $this->locator->find($this->context, $key);
        $this->guard->authorize($this->context, $ability, $node, $ability === Ability::Download ? OperationKind::ContentEgress : ($ability === Ability::Edit ? OperationKind::Mutation : OperationKind::Metadata));
        if ($node->kind !== NodeKind::File || $node->file()->first() === null) {
            throw new NotAFileException;
        }

        return $node;
    }

    private function revision(Node $node, FileRevision|int|string|null $key): FileRevision
    {
        $file = $node->file()->firstOrFail();
        $id = $key instanceof FileRevision ? $key->getRawOriginal($key->getKeyName()) : ($key ?? $file->current_revision_id);

        return $file->versions()->whereKey($id)->whereIn('status', ['committed', 'superseded'])->first() ?? throw NodeNotFoundException::forId($node->id);
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
