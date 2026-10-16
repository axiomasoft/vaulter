<?php

declare(strict_types=1);

namespace Vaulter\Drive;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Drive\DriveHandle as Contract;
use Vaulter\Contracts\Drive\DriveHandleFactory as Factory;
use Vaulter\Models\Drive\Drive;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Tenancy\TenancyBoundary;

#[Internal]
final readonly class DriveHandleFactory implements Factory
{
    public function __construct(private Container $container, private VaulterConfig $config)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \LogicException('Node sequence APIs require a 64-bit PHP runtime.');
        }
    }

    public function forOwner(Model $owner, string $profile): Contract
    {
        $owner = $this->owner($owner);
        $drive = $this->container->make(DriveProvisioner::class)->forOwner(owner: $owner, panel: $profile);

        return $this->forDrive($drive);
    }

    public function findForOwner(Model $owner, string $profile): ?Contract
    {
        $owner = $this->owner($owner);
        $drive = $this->container->make(DriveReadRepository::class)->forOwner(owner: $owner, panel: $profile);

        return $drive === null ? null : $this->forDrive($drive);
    }

    public function forDrive(Drive $drive): Contract
    {
        $key = $drive->getRawOriginal($drive->getKeyName());
        if (!$drive->exists || (!is_int($key) && !is_string($key))) {
            throw new InvalidArgumentException('A persisted drive is required.');
        }
        $class = $this->config->model('drive');
        $fresh = $this->container->make(TenancyBoundary::class)->assertDrive($drive);

        return new DriveHandle(drive: $fresh, container: $this->container);
    }

    private function owner(Model $owner): Model
    {
        $owner = $this->container->make(TenancyBoundary::class)->assertOwner($owner);
        if (!$owner->exists || $owner->getKey() === null) {
            throw new InvalidArgumentException('A persisted owner is required.');
        }

        return $owner;
    }
}
