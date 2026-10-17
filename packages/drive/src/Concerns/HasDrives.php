<?php

declare(strict_types=1);

namespace Vaulter\Drive\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Vaulter;
use Vaulter\Tenancy\Tenancy;

/**
 * Mounts drives on a host model. With vaulter-drive.drives.delete_with_owner
 * the drives of a deleted owner are deleted too; a host with SoftDeletes reacts to
 * forceDeleted, not to deleted, so a soft-deleted owner keeps its drives.
 *
 * @mixin Model
 */
#[Api]
trait HasDrives
{
    public static function bootHasDrives(): void
    {
        $event = method_exists(static::class, 'forceDeleted') ? 'forceDeleted' : 'deleted';

        static::registerModelEvent($event, static function (Model $owner): void {
            $config = self::vaulter(DriveConfig::class);
            if (!self::vaulter(DriveConfig::class)->drivesDeleteWithOwner()) {
                return;
            }
            // The owner is gone, so its drives are deleted outside any tenant scope.
            Tenancy::without(static function () use ($owner, $config): void {
                $factory = self::vaulter(DriveHandleFactory::class);
                $drives = $config->model('drive')::query()->where('owner_type', $owner->getMorphClass())
                    ->where('owner_id', $owner->getKey())->where('status', '!=', DriveStatus::Deleting->value)->get();
                foreach ($drives as $drive) {
                    $factory->forDrive($drive)->asSystem('owner-deleted')->delete();
                }
            });
        });
    }

    public function drive(?string $profile = null): DriveHandle
    {
        return self::vaulter(Vaulter::class)->drive($this, $profile);
    }

    /** @return MorphMany<Drive, $this> */
    public function drives(): MorphMany
    {
        return $this->morphMany(self::vaulter(DriveConfig::class)->model('drive'), 'owner');
    }

    /**
     * An Eloquent model has no constructor injection, so this trait is the one place
     * that asks the container (the ServiceLocatorRatchetTest baseline lists it once).
     *
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T
     */
    private static function vaulter(string $class): object
    {
        return app($class);
    }
}
