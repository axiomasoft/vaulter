<?php

declare(strict_types=1);

namespace Vaulter\Actions\Drive;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateDriveFolderData;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Events\Drive\NodeCreated;
use Vaulter\Exceptions\Drive\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;

/**
 * Create a kind=folder node under a parent (or the storage root) — §2.2. The
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
    ) {}

    public function execute(CreateDriveFolderData $data): Node
    {
        $parentId = $data->parent?->id;

        if ($this->nodes->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $data->name)) {
            throw NodeNameConflictException::forName(name: $data->name, parentId: $parentId);
        }

        return DB::transaction(function () use ($data, $parentId): Node {
            $node = $this->store->create([
                'storage_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Folder,
                'name' => $data->name,
                'size_bytes' => 0,
                'owner_id' => $data->actorId,
                'created_by' => $data->actorId,
            ]);

            event(new NodeCreated(node: $node, actorId: $data->actorId));

            return $node;
        });
    }
}
