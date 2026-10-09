<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\DrivePolicyEnforcer;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\DriveLifecycle;
use Vaulter\Support\TreeGuard;

/**
 * Create a kind=folder node under a parent (or the storage root) — §2.2. The
 * parent row is locked and must be a live folder of the same storage; the
 * sibling-name conflict is surfaced deterministically (NodeNameConflictException)
 * rather than silently auto-renamed: Drive never invents a name behind the
 * caller's back.
 *
 * The name passes the drive's name policy; the drive policy's children and depth
 * limits are checked under the parent lock.
 *
 * Operations authorize before this command; the destination is checked again under lock.
 */
final readonly class CreateFolderAction
{
    public function __construct(
        private NodeStoreRepository $store,
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private DrivePolicyEnforcer $policy,
        private NameConflictResolver $names,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(OperationContext $context, CreateFolderInput $data): Node
    {
        $name = $this->policy->name(drive: $context->drive, name: $data->name);
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        return $this->database->transaction(function () use ($context, $data, $name, $parentId): Node {
            $this->database->lockTreeShared($data->storage->id);
            $this->lifecycle->assertWritable($context);

            $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
            if (!$this->tree->sameStorage($parent, $data->storage->id)) {
                throw CrossDriveOperationException::between(fromStorageId: $data->storage->id, toStorageId: $parent->drive_id);
            }

            $this->tree->assertActiveFolder($parent);
            $this->policy->assertChildren(drive: $context->drive, parentId: $parentId);
            $this->policy->assertDepth(drive: $context->drive, parent: $parent);

            $name = $this->names->resolve($context->drive, $parentId, $name, $data->onConflict);

            $node = $this->store->create([
                'drive_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Folder,
                'name' => $name,
                'size_bytes' => 0,
                'created_by' => $context->actor->ref()->id,
            ]);

            $this->recorder->record(new FolderCreated(node: $node, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $node;
        });
    }
}
