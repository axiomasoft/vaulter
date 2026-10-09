<?php

declare(strict_types=1);

namespace Vaulter\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Models\Drive\Drive;
use Vaulter\VaulterManager;

/**
 * Mounts drives on a host model (PLAN2.D27). With vaulter.drives.delete_with_owner
 * the drives of a deleted owner are deleted too; a host with SoftDeletes reacts to
 * forceDeleted, not to deleted, so a soft-deleted owner keeps its drives.
 *
 * @mixin Model
 */
trait HasDrives
{
    public static function bootHasDrives(): void
    {
        $event = method_exists(static::class, 'forceDeleted') ? 'forceDeleted' : 'deleted';

        static::registerModelEvent($event, static function (Model $owner): void {
            $config = self::vaulter(VaulterConfig::class);
            if (!$config->drivesDeleteWithOwner()) {
                return;
            }
            // The owner is gone, so its drives are deleted outside any tenant scope.
            self::vaulter(VaulterManager::class)->withoutTenancy(static function () use ($owner, $config): void {
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
        return self::vaulter(VaulterManager::class)->drive($this, $profile);
    }

    /** @return MorphMany<Drive, $this> */
    public function drives(): MorphMany
    {
        return $this->morphMany(self::vaulter(VaulterConfig::class)->model('drive'), 'owner');
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
