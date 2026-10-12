<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Drive\DriveHandle as DriveHandleContract;
use Vaulter\Contracts\Drive\DriveHandleFactory as DriveHandleFactoryContract;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Actions\Nodes\RenameNodeAction;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Actions\Trash\TrashNodeAction;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The vaulter-drive implementation of the core {@see DriveHandleFactory} seam
 * (P2 #5 / ADR-0015). It wires the DriveProvisioner, NodeReadRepository and the eight
 * Drive Actions into a {@see DriveHandle}, so `Vaulter::for()` / `Vaulter::forStorage()`
 * (defined in core's VaulterManager) return a working handle without core ever
 * referencing a Drive class. Bound to the contract by VaulterDriveServiceProvider.
 */
final readonly class DriveHandleFactory implements DriveHandleFactoryContract
{
    public function __construct(
        private Container $container,
    ) {}

    public function forOwner(Model $entity, string $panel): DriveHandleContract
    {
        $storage = $this->container->make(DriveProvisioner::class)->forOwner(owner: $entity, panel: $panel);

        return $this->forStorage($storage);
    }

    public function forStorage(Drive $storage): DriveHandleContract
    {
        return new DriveHandle(
            storage: $storage,
            profiles: $this->container->make(ProfileRegistry::class),
            storages: $this->container->make(DriveProvisioner::class),
            nodes: $this->container->make(NodeReadRepository::class),
            createFolder: $this->container->make(CreateFolderAction::class),
            uploadFile: $this->container->make(IngestFileAction::class),
            moveNode: $this->container->make(MoveNodeAction::class),
            renameNode: $this->container->make(RenameNodeAction::class),
            copyNode: $this->container->make(CopyNodeAction::class),
            trashNode: $this->container->make(TrashNodeAction::class),
            restoreNode: $this->container->make(RestoreNodeAction::class),
            forceDeleteNode: $this->container->make(PurgeNodeAction::class),
        );
    }
}
