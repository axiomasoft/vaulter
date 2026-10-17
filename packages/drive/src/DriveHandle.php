<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Actions\Drives\UnarchiveDriveAction;
use Vaulter\Drive\Contracts\Access\AccessOperations;
use Vaulter\Drive\Contracts\Activity\ActivityOperations;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Contracts\DriveHandle as Contract;
use Vaulter\Drive\Contracts\FileOperations;
use Vaulter\Drive\Contracts\FolderOperations;
use Vaulter\Drive\Contracts\NodeOperations;
use Vaulter\Drive\Contracts\Sharing\ShareOperations;
use Vaulter\Drive\Contracts\TrashOperations;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\DriveSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\QuotaSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\ChangeOperations;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Kernel;
use Vaulter\Modules\MissingExtensionException;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

#[Api]
final readonly class DriveHandle implements Contract
{
    #[Internal]
    public function __construct(private Drive $drive, private Container $container, private ?Actor $explicitActor = null, private ?string $correlationId = null, private ?string $idempotencyKey = null, private ?string $requestFingerprint = null) {}

    /** The Eloquent row, for Vaulter packages and tests; hosts use snapshot(). */
    #[Internal]
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
        return new self($this->drive, $this->container, Actor::user($user), $this->correlationId, $this->idempotencyKey, $this->requestFingerprint);
    }

    public function asSystem(string $reason): static
    {
        return new self($this->drive, $this->container, Actor::system($reason), $this->correlationId, $this->idempotencyKey, $this->requestFingerprint);
    }

    public function withCorrelationId(string $correlationId): static
    {
        if (trim($correlationId) === '' || strlen($correlationId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $correlationId) === 1) {
            throw new \InvalidArgumentException('Invalid correlation ID.');
        }

        return new self($this->drive, $this->container, $this->explicitActor, $correlationId, $this->idempotencyKey, $this->requestFingerprint);
    }

    public function withIdempotencyKey(string $key, ?string $requestFingerprint = null): static
    {
        if ($key === '' || strlen($key) > 255) {
            throw new \InvalidArgumentException('[Vaulter] An idempotency key must be between 1 and 255 bytes.');
        }

        return new self($this->drive, $this->container, $this->explicitActor, $this->correlationId, $key, $requestFingerprint);
    }

    /** One context and correlation per operation, no cached ACL result. */
    #[Internal]
    private function context(): DriveContext
    {
        return DriveContext::for(
            drive: $this->model(),
            actor: $this->actor(),
            correlationId: $this->correlationId ?? (string) Str::ulid(),
            idempotencyKey: $this->idempotencyKey,
            requestFingerprint: $this->requestFingerprint,
        );
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
        return $this->operations(Operations\AccessOperations::class);
    }

    public function shares(): ShareOperations
    {
        return $this->operations(Operations\ShareOperations::class);
    }

    public function activity(): ActivityOperations
    {
        return $this->operations(Operations\ActivityOperations::class);
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
