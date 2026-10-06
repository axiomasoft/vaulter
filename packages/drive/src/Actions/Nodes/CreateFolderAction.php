<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Create a kind=folder node under a parent (or the storage root) — §2.2. The
 * parent row is locked and must be a live folder of the same storage; the
 * sibling-name conflict is surfaced deterministically (NodeNameConflictException)
 * rather than silently auto-renamed: Drive never invents a name behind the
 * caller's back.
 *
 * Authorization (M2a / H.2) is enforced UPSTREAM at the HTTP controller boundary
 * (NodeController::guardCreate → AccessResolver), so the Action stays pure: it
 * receives an already-authorized parent/storage and only does the row work.
 */
final readonly class CreateFolderAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DriveConfig $driveConfig,
    ) {}

    public function execute(CreateFolderInput $data): Node
    {
        $name = NodeName::normalize(name: $data->name, maxLength: $this->driveConfig->nameMaxLength());
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        return $this->database->transaction(function () use ($data, $name, $parentId): Node {
            $this->database->lockTreeShared($data->storage->id);

            $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
            if (!$this->tree->sameStorage($parent, $data->storage->id)) {
                throw CrossDriveOperationException::between(fromStorageId: $data->storage->id, toStorageId: $parent->drive_id);
            }

            $this->tree->assertActiveFolder($parent);

            if ($this->nodes->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $name)) {
                throw NodeNameConflictException::forName(name: $name, parentId: $parentId);
            }

            $node = $this->store->create([
                'drive_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Folder,
                'name' => $name,
                'size_bytes' => 0,
                'owner_id' => $data->actorId,
                'created_by' => $data->actorId,
            ]);

            event(new FolderCreated(node: $node, actorId: $data->actorId));

            return $node;
        });
    }
}
