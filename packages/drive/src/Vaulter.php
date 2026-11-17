<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Queries\VisibleDrivesQuery;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Kernel;
use Vaulter\Modules\MissingExtensionException;

#[Api]
final readonly class Vaulter
{
    #[Internal]
    public function __construct(private Container $container) {}

    public function drive(Model $ownerOrDrive, ?string $profile = null): DriveHandle
    {
        $factory = $this->driveFactory();
        if ($ownerOrDrive instanceof Drive) {
            $handle = $factory->forDrive($ownerOrDrive);
            if ($profile !== null && $handle->model()->profile !== $profile) {
                throw new InvalidArgumentException('Drive profile mismatch.');
            }

            return $handle;
        }
        $profile ??= $this->profiles()->default()->getKey();
        $this->profiles()->get($profile);

        return $factory->forOwner(owner: $ownerOrDrive, profile: $profile);
    }

    public function findDrive(Model $owner, ?string $profile = null): ?DriveHandle
    {
        $factory = $this->driveFactory();
        $profile ??= $this->profiles()->default()->getKey();
        $this->profiles()->get($profile);

        return $factory->findForOwner(owner: $owner, profile: $profile);
    }

    /**
     * The drive with this key in the current tenant, or null. The handle's operations authorize
     * every act themselves; hosts resolve keys they took from an authorized listing such as drives().
     */
    public function findDriveById(string|int $id): ?DriveHandle
    {
        return $this->driveFactory()->findById($id);
    }

    /**
     * The drives the actor may open, in key order: a drive is listed when the actor can view its
     * root, decided before the page is cut. Opaque cursor; perPage and cursor are the options used.
     *
     * @return Page<DriveSnapshot>
     */
    public function drives(Authorizable $actor, ?ListOptions $options = null): Page
    {
        return $this->container->make(VisibleDrivesQuery::class)->execute($this->driveFactory(), $actor, $options ?? new ListOptions);
    }

    public function profiles(): ProfileRegistry
    {
        return $this->container->make(ProfileRegistry::class);
    }

    public function handlers(): FileHandlerRegistry
    {
        return $this->container->make(FileHandlerRegistry::class);
    }

    public function permissions(): PermissionDriverManager
    {
        return $this->container->make(PermissionDriverManager::class);
    }

    private function driveFactory(): DriveHandleFactory
    {
        try {
            return $this->container->make(Kernel::class)->capabilities()->require(DriveHandleFactory::class);
        } catch (MissingExtensionException) {
            throw MissingPackageException::forContract(DriveHandleFactory::class, 'axiomasoft/vaulter-drive');
        }
    }
}
