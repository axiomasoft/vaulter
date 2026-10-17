<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * The drives the acting panel admin may open, as authorized handles. Visibility is the
 * handle's own decision (the root node must be viewable); the panel never evaluates
 * grants itself, so a driver or role change in core reaches the panel without a change here.
 */
#[Internal]
final readonly class PanelDriveAccess
{
    public function __construct(private VaulterConfig $config, private TenancyBoundary $boundary) {}

    /** @return list<DriveHandle> */
    public function handles(): array
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return [];
        }

        $driveClass = $this->config->model('drive');
        $handles = [];

        /** @var Drive $drive */
        foreach ($this->boundary->constrain($driveClass::query())->lazyById(100) as $drive) {
            $handle = Vaulter::drive($drive)->actingAs($actor);

            try {
                $handle->root();
            } catch (VaulterException) {
                // No View on the root: the drive does not exist for this admin.
                continue;
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    /**
     * The acting admin's handle for one drive, without judging visibility: the handle's
     * operations authorize every act themselves, so a forged or stale row answers with
     * the operation's own not-found / permission-denied instead of a panel-made guess.
     */
    public function handle(string $driveId): ?DriveHandle
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return null;
        }

        $driveClass = $this->config->model('drive');
        $drive = $this->boundary->constrain($driveClass::query())->whereKey($driveId)->first();

        return $drive instanceof Drive ? Vaulter::drive($drive)->actingAs($actor) : null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (DriveHandle $handle): string => Identity::stringify($handle->model()->getKey()), $this->handles());
    }
}
