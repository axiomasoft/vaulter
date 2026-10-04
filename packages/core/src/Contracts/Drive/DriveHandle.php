<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * The ergonomic Drive handle bound to one (Storage, panel) — the surface
 * `Vaulter::for($entity)` / `Vaulter::forStorage($storage)` returns (§2.1).
 *
 * The contract lives in core (the kernel owns the Node/Storage models and the
 * public facade entry point) while the concrete handle ships in vaulter-drive
 * (P2 #5 / ADR-0015): core defines what a handle CAN do, the drive package binds
 * the implementation. A host that has not installed vaulter-drive still type-hints
 * against this interface; resolving it fails loudly with an install hint.
 */
interface DriveHandle
{
    public function storage(): Drive;

    public function panel(): string;

    /**
     * The storage's root folder node (§2.1).
     */
    public function root(): Node;

    public function createFolder(string $name, ?Node $parent = null, int|string|null $actorId = null): Node;

    /**
     * Upload bytes into the Drive (§2.4). $source may be a PSR StreamInterface, an
     * SplFileInfo / UploadedFile, an open stream resource, or a raw string.
     */
    public function upload(
        mixed $source,
        ?Node $into = null,
        ?string $name = null,
        ?string $mimeType = null,
        ?string $originalName = null,
        int|string|null $actorId = null,
    ): Node;

    /**
     * Upload a NEW VERSION onto an existing file node (§2.4).
     */
    public function uploadVersion(
        Node $target,
        mixed $source,
        ?string $mimeType = null,
        ?string $originalName = null,
        int|string|null $actorId = null,
    ): Node;

    public function move(Node $node, ?Node $into = null, int|string|null $actorId = null): Node;

    public function rename(Node $node, string $name, int|string|null $actorId = null): Node;

    public function copy(Node $node, ?Node $into = null, ?string $name = null, int|string|null $actorId = null): Node;

    public function trash(Node $node, int|string|null $actorId = null): Node;

    public function restore(Node $node, int|string|null $actorId = null): Node;

    public function forceDelete(Node $node, int|string|null $actorId = null): void;

    /**
     * Direct children of a folder (or the root when null) — the hot listing.
     *
     * @return Collection<int, Node>
     */
    public function list(?Node $folder = null): Collection;
}
