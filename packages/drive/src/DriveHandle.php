<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Access\AccessOperations;
use Vaulter\Contracts\Activity\ActivityOperations;
use Vaulter\Contracts\Drive\DriveExtension;
use Vaulter\Contracts\Drive\DriveHandle as Contract;
use Vaulter\Contracts\Drive\FileOperations;
use Vaulter\Contracts\Drive\FolderOperations;
use Vaulter\Contracts\Drive\NodeOperations;
use Vaulter\Contracts\Drive\TrashOperations;
use Vaulter\Contracts\Sharing\ShareOperations;
use Vaulter\Data\Snapshots\DriveSnapshot;
use Vaulter\Data\Snapshots\Mapping\DriveSnapshotMapper;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\QuotaSnapshot;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Drives\UnarchiveDriveAction;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Kernel;
use Vaulter\Models\Drive\Drive;
use Vaulter\Modules\MissingExtensionException;
use Vaulter\Operations\ChangeOperations;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

final readonly class DriveHandle implements Contract
{
    public function __construct(private Drive $drive, private Container $container, private ?Actor $explicitActor = null, private ?string $correlationId = null) {}

    /** @internal The Eloquent row, for Vaulter packages and tests; hosts use snapshot(). */
    public function model(): Drive
    {
        $drive = $this->container->make(TenancyBoundary::class)->assertDrive($this->drive);
        // A drive being deleted is absent for everyone but its own purge.
        $purging = $this->explicitActor?->isSystem() === true && $this->explicitActor->ref()->reason === OperationGuard::PURGE_REASON;
        if ($drive->status === DriveStatus::Deleting && !$purging) {
            throw StorageNotFoundException::forId($drive->id);
        }

        return $drive;
    }

    public function snapshot(): DriveSnapshot
    {
        return $this->container->make(DriveSnapshotMapper::class)->map($this->model());
    }

    public function quota(): QuotaSnapshot
    {
        return $this->container->make(DriveSnapshotMapper::class)->quota($this->model());
    }

    public function archive(): DriveSnapshot
    {
        $context = $this->authorizedLifecycle();

        return $this->container->make(DriveSnapshotMapper::class)->map($this->container->make(ArchiveDriveAction::class)->execute($context));
    }

    public function unarchive(): DriveSnapshot
    {
        $context = $this->authorizedLifecycle();

        return $this->container->make(DriveSnapshotMapper::class)->map($this->container->make(UnarchiveDriveAction::class)->execute($context));
    }

    public function delete(): DriveSnapshot
    {
        $context = $this->authorizedLifecycle();

        return $this->container->make(DriveSnapshotMapper::class)->map($this->container->make(DeleteDriveAction::class)->execute($context));
    }

    /** Manage on the root, or the system actor; an archived drive still allows its own lifecycle. */
    private function authorizedLifecycle(): DriveContext
    {
        $context = $this->context();
        $rootId = $context->drive->root_node_id ?? throw NodeNotFoundException::forId('root');
        $root = $this->container->make(NodeLocator::class)->find($context, $rootId);
        $this->container->make(OperationGuard::class)->authorize($context, Ability::Manage, $root);

        return $context;
    }

    public function profile(): Profile
    {
        return $this->container->make(ProfileRegistry::class)->get($this->model()->profile);
    }

    public function actor(): Actor
    {
        if ($this->explicitActor !== null) {
            return $this->explicitActor;
        }
        $user = Auth::user();
        if (!$user instanceof Authorizable) {
            throw MissingActorException::required();
        }

        return Actor::user($user);
    }

    public function actingAs(Authorizable $user): static
    {
        return new self($this->drive, $this->container, Actor::user($user), $this->correlationId);
    }

    public function asSystem(string $reason): static
    {
        return new self($this->drive, $this->container, Actor::system($reason), $this->correlationId);
    }

    public function withCorrelationId(string $correlationId): static
    {
        if (trim($correlationId) === '' || strlen($correlationId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $correlationId) === 1) {
            throw new \InvalidArgumentException('Invalid correlation ID.');
        }

        return new self($this->drive, $this->container, $this->explicitActor, $correlationId);
    }

    /** @internal One context and correlation per operation, no cached ACL result. */
    private function context(): DriveContext
    {
        return DriveContext::for(drive: $this->model(), actor: $this->actor(), correlationId: $this->correlationId ?? (string) Str::ulid());
    }

    public function root(): NodeSnapshot
    {
        $context = $this->context();
        $rootId = $context->drive->root_node_id ?? throw NodeNotFoundException::forId('root');
        $root = $this->container->make(NodeLocator::class)->find($context, $rootId);
        $this->container->make(OperationGuard::class)->authorize($context, Ability::View, $root);

        return $this->container->make(NodeSnapshotMapper::class)->map($root, $context->actor->ref());
    }

    public function nodes(): NodeOperations
    {
        return $this->operations(Operations\NodeOperations::class);
    }

    public function folders(): FolderOperations
    {
        return $this->operations(Operations\FolderOperations::class);
    }

    public function files(): FileOperations
    {
        return $this->operations(Operations\FileOperations::class);
    }

    public function trash(): TrashOperations
    {
        return $this->operations(Operations\TrashOperations::class);
    }

    public function access(): AccessOperations
    {
        return $this->operations(\Vaulter\Operations\AccessOperations::class);
    }

    public function shares(): ShareOperations
    {
        return $this->operations(\Vaulter\Operations\ShareOperations::class);
    }

    public function activity(): ActivityOperations
    {
        return $this->operations(\Vaulter\Operations\ActivityOperations::class);
    }

    public function changes(): ChangeOperations
    {
        return $this->operations(ChangeOperations::class);
    }

    /** The resumable-upload operations of the drive: the drive module's own, not an extension. */
    public function uploads(): UploadOperations
    {
        return $this->container->makeWith(UploadOperations::class, ['handle' => $this, 'context' => $this->context()]);
    }

    /**
     * The operations a module declared in the `drive` slot under the FQCN `$operations`; a key no active
     * module declares throws {@see MissingExtensionException}, however the class is bound.
     *
     * @template T of object
     *
     * @param  class-string<T> $operations
     * @return T
     */
    public function extension(string $operations): object
    {
        $class = $this->container->make(Kernel::class)->extensions()->handlerClass(DriveExtension::class, $operations);

        $instance = $this->container->makeWith($class, ['handle' => $this, 'context' => $this->context()]);
        if (!$instance instanceof $operations) {
            throw new \LogicException('An extension binding must implement its requested class or contract.');
        }

        return $instance;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T
     */
    private function operations(string $class): object
    {
        $instance = $this->container->makeWith($class, ['context' => $this->context()]);
        if (!$instance instanceof $class) {
            throw new \LogicException('An operations binding must implement its requested class or contract.');
        }

        return $instance;
    }
}
