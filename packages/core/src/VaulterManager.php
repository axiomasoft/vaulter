<?php

declare(strict_types=1);

namespace Vaulter;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Exceptions\Support\MissingPackageException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Modules\MissingExtensionException;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Tenancy\Tenancy;

#[Api]
final readonly class VaulterManager
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

    /** @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function withoutTenancy(Closure $callback): mixed
    {
        return Tenancy::without($callback);
    }

    private function driveFactory(): DriveHandleFactory
    {
        try {
            return $this->container->make(Kernel::class)->capabilities()->require(DriveHandleFactory::class);
        } catch (MissingExtensionException) {
            throw MissingPackageException::forContract(DriveHandleFactory::class, 'axioma-studio/vaulter-drive');
        }
    }
}
