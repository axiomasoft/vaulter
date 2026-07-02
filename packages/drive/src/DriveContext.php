<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use SplFileInfo;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Actions\CopyNodeAction;
use Vaulter\Drive\Actions\CreateFolderAction;
use Vaulter\Drive\Actions\ForceDeleteNodeAction;
use Vaulter\Drive\Actions\MoveNodeAction;
use Vaulter\Drive\Actions\RenameNodeAction;
use Vaulter\Drive\Actions\RestoreNodeAction;
use Vaulter\Drive\Actions\TrashNodeAction;
use Vaulter\Drive\Actions\UploadFileAction;
use Vaulter\Drive\Data\CopyNodeData;
use Vaulter\Drive\Data\CreateDriveFolderData;
use Vaulter\Drive\Data\MoveNodeData;
use Vaulter\Drive\Data\RenameNodeData;
use Vaulter\Drive\Data\UploadFileData;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The ergonomic Drive handle bound to one (Storage, panel) for a host entity —
 * the surface `Vaulter::for($entity)` returns. Every method is a thin delegation
 * to a final readonly Action (write) or NodeReadRepository (read); the handle adds
 * no business logic, only the convenience of a pre-resolved storage + the
 * stream-normalisation that keeps the Actions free of HTTP/PSR types.
 *
 * Authorization (M2a / H.2) is enforced at the HTTP controller boundary
 * (NodeController + AccessResolver) before these methods are reached — the handle
 * itself stays a pure delegation surface and performs no access checks, mirroring
 * the legacy DocumentVisibilityService placement canon.
 */
final readonly class DriveContext implements DriveHandle
{
    public function __construct(
        private Storage $storage,
        private string $panel,
        private StorageManager $storages,
        private NodeReadRepository $nodes,
        private CreateFolderAction $createFolder,
        private UploadFileAction $uploadFile,
        private MoveNodeAction $moveNode,
        private RenameNodeAction $renameNode,
        private CopyNodeAction $copyNode,
        private TrashNodeAction $trashNode,
        private RestoreNodeAction $restoreNode,
        private ForceDeleteNodeAction $forceDeleteNode,
    ) {}

    public function storage(): Storage
    {
        return $this->storage;
    }

    public function panel(): string
    {
        return $this->panel;
    }

    /**
     * The storage's root folder node (§2.1).
     */
    public function root(): Node
    {
        return $this->storages->root($this->storage);
    }

    public function createFolder(string $name, ?Node $parent = null, int|string|null $actorId = null): Node
    {
        return $this->createFolder->execute(new CreateDriveFolderData(
            storage: $this->storage,
            name: $name,
            parent: $parent ?? $this->root(),
            actorId: $actorId,
        ));
    }

    /**
     * Upload bytes into the Drive (§2.4). $source may be a PSR StreamInterface, an
     * SplFileInfo / UploadedFile, an open stream resource, or a raw string — all
     * normalised to a seekable resource before the Action stages and dedups it.
     * $into is the destination folder (null = root); $name overrides the stored
     * name. Typed mixed so core need not hard-require psr/http-message.
     */
    public function upload(
        mixed $source,
        ?Node $into = null,
        ?string $name = null,
        ?string $mimeType = null,
        ?string $originalName = null,
        int|string|null $actorId = null,
    ): Node {
        $stream = $this->toStream($source);
        $resolvedOriginalName = $originalName ?? $this->originalNameFor($source) ?? ($name ?? 'upload.bin');

        return $this->uploadFile->execute(new UploadFileData(
            storage: $this->storage,
            stream: $stream,
            originalName: $resolvedOriginalName,
            mimeType: $mimeType ?? $this->mimeTypeFor($source),
            into: $into ?? $this->root(),
            name: $name,
            actorId: $actorId,
        ));
    }

    /**
     * Upload a NEW VERSION onto an existing file node (§2.4): version_no bumps,
     * the head repoints, the prior version is superseded.
     */
    public function uploadVersion(
        Node $target,
        mixed $source,
        ?string $mimeType = null,
        ?string $originalName = null,
        int|string|null $actorId = null,
    ): Node {
        $stream = $this->toStream($source);
        $resolvedOriginalName = $originalName ?? $this->originalNameFor($source) ?? $target->name;

        return $this->uploadFile->execute(new UploadFileData(
            storage: $this->storage,
            stream: $stream,
            originalName: $resolvedOriginalName,
            mimeType: $mimeType ?? $this->mimeTypeFor($source),
            targetNode: $target,
            actorId: $actorId,
        ));
    }

    public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node
    {
        return $this->moveNode->execute(new MoveNodeData(
            node: $node,
            into: $into ?? $this->root(),
            actorId: $actorId,
        ));
    }

    public function rename(Node $node, string $name, int|string|null $actorId = null): Node
    {
        return $this->renameNode->execute(new RenameNodeData(
            node: $node,
            name: $name,
            actorId: $actorId,
        ));
    }

    public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node
    {
        return $this->copyNode->execute(new CopyNodeData(
            node: $node,
            into: $into ?? $this->root(),
            name: $name,
            actorId: $actorId,
        ));
    }

    public function trash(Node $node, int|string|null $actorId = null): Node
    {
        return $this->trashNode->execute(node: $node, actorId: $actorId);
    }

    public function restore(Node $node, int|string|null $actorId = null): Node
    {
        return $this->restoreNode->execute(node: $node, actorId: $actorId);
    }

    public function forceDelete(Node $node, int|string|null $actorId = null): void
    {
        $this->forceDeleteNode->execute(node: $node, actorId: $actorId);
    }

    /**
     * Direct children of a folder (or the root when null) — the hot listing.
     *
     * @return Collection<int, Node>
     */
    public function list(?Node $folder = null): Collection
    {
        return $this->nodes->children(storage: $this->storage, parent: $folder ?? $this->root())->get();
    }

    /**
     * Normalise an upload source to a seekable byte stream resource. A PSR
     * StreamInterface is detached / re-read; an SplFileInfo / UploadedFile is
     * opened; an already-open resource is used as-is.
     *
     * @return resource
     */
    private function toStream(mixed $source): mixed
    {
        if (is_resource($source)) {
            return $source;
        }

        // PSR-7 StreamInterface — detected without a hard dependency: core does
        // not require psr/http-message, so we guard on its presence at runtime.
        if (is_object($source) && is_a($source, 'Psr\Http\Message\StreamInterface')) {
            return $this->streamFromString((string) $source);
        }

        if ($source instanceof SplFileInfo) {
            $handle = @fopen($source->getPathname(), 'rb');

            if ($handle === false) {
                throw new RuntimeException("Unable to open upload source [{$source->getPathname()}].");
            }

            return $handle;
        }

        if (is_string($source)) {
            return $this->streamFromString($source);
        }

        throw new RuntimeException(
            'Vaulter upload source must be a stream resource, PSR StreamInterface, SplFileInfo/UploadedFile or string.',
        );
    }

    /**
     * @return resource
     */
    private function streamFromString(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory stream for the upload.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function originalNameFor(mixed $source): ?string
    {
        if ($source instanceof SplFileInfo) {
            // UploadedFile exposes the client's original filename; SplFileInfo
            // falls back to the on-disk basename.
            if (method_exists($source, 'getClientOriginalName')) {
                /** @var string $name */
                $name = $source->getClientOriginalName();

                return $name;
            }

            return $source->getFilename();
        }

        return null;
    }

    private function mimeTypeFor(mixed $source): ?string
    {
        if ($source instanceof SplFileInfo && method_exists($source, 'getMimeType')) {
            /** @var string|null $mime */
            $mime = $source->getMimeType();

            return $mime;
        }

        return null;
    }
}
