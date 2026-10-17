<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;

/**
 * Turns the scalar route parameters of the versioned API into the drive an operation
 * runs on. Both lookups go through the tenant-scoped repositories, and a drive that is
 * missing, belongs to another tenant or is being deleted answers exactly like one that
 * never existed, so a caller cannot probe ids across tenants. Nested resources (grants,
 * shares, revisions) are checked against their node by the operations themselves.
 */
#[Internal]
final readonly class HttpResourceResolver
{
    public function __construct(
        private DriveReadRepository $storages,
        private NodeReadRepository $nodes,
    ) {}

    public function drive(string $id): Drive
    {
        $drive = $this->storages->find($id);

        return $drive instanceof Drive && $drive->status !== DriveStatus::Deleting
            ? $drive
            : throw StorageNotFoundException::forId($id);
    }

    /** The drive holding a node, trashed nodes included; an unknown node is node_not_found. */
    public function driveOfNode(string $nodeId): Drive
    {
        $node = $this->nodes->find($nodeId) ?? throw NodeNotFoundException::forId($nodeId);
        $drive = $this->storages->find($node->drive_id);

        return $drive instanceof Drive && $drive->status !== DriveStatus::Deleting
            ? $drive
            : throw NodeNotFoundException::forId($nodeId);
    }
}
