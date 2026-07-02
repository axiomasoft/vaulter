<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Drive\Actions\CopyNodeAction;
use Vaulter\Drive\Actions\CreateFolderAction;
use Vaulter\Drive\Actions\ForceDeleteNodeAction;
use Vaulter\Drive\Actions\MoveNodeAction;
use Vaulter\Drive\Actions\RenameNodeAction;
use Vaulter\Drive\Actions\RestoreNodeAction;
use Vaulter\Drive\Actions\TrashNodeAction;
use Vaulter\Drive\Actions\UploadFileAction;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The vaulter-drive implementation of the core {@see DriveHandleFactory} seam
 * (P2 #5 / ADR-0015). It wires the StorageManager, NodeReadRepository and the eight
 * Drive Actions into a {@see DriveContext}, so `Vaulter::for()` / `Vaulter::forStorage()`
 * (defined in core's VaulterManager) return a working handle without core ever
 * referencing a Drive class. Bound to the contract by VaulterDriveServiceProvider.
 */
final readonly class DriveManager implements DriveHandleFactory
{
    public function __construct(
        private Container $container,
    ) {}

    public function forOwner(Model $entity, string $panel): DriveHandle
    {
        $storage = $this->container->make(StorageManager::class)->forOwner(owner: $entity, panel: $panel);

        return $this->forStorage($storage);
    }

    public function forStorage(Storage $storage): DriveHandle
    {
        return new DriveContext(
            storage: $storage,
            panel: $storage->panel,
            storages: $this->container->make(StorageManager::class),
            nodes: $this->container->make(NodeReadRepository::class),
            createFolder: $this->container->make(CreateFolderAction::class),
            uploadFile: $this->container->make(UploadFileAction::class),
            moveNode: $this->container->make(MoveNodeAction::class),
            renameNode: $this->container->make(RenameNodeAction::class),
            copyNode: $this->container->make(CopyNodeAction::class),
            trashNode: $this->container->make(TrashNodeAction::class),
            restoreNode: $this->container->make(RestoreNodeAction::class),
            forceDeleteNode: $this->container->make(ForceDeleteNodeAction::class),
        );
    }
}
