<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Carbon\CarbonImmutable;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Exceptions\VaulterException;

/**
 * The drives the acting panel admin may open, as authorized handles. Visibility is the
 * drive entry point's own decision (`Vaulter::drives()` lists a drive whose root the actor can
 * view); the panel never evaluates grants itself, so a driver or role change in the drive
 * reaches the panel without a change here.
 *
 * @phpstan-type DriveRow array{id: string, name: string, owner_type: string, owner_id: string, profile: string, status: DriveStatus, used_bytes: int, quota_bytes: ?int, created_at: CarbonImmutable}
 */
#[Internal]
final readonly class PanelDriveAccess
{
    private const int PAGE = 200;

    /** @return list<DriveHandle> */
    public function handles(): array
    {
        $handles = [];

        foreach ($this->snapshots() as $snapshot) {
            $handle = $this->handle($snapshot->id);

            if ($handle !== null) {
                $handles[] = $handle;
            }
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

        return Vaulter::findDriveById($driveId)?->actingAs($actor);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (DriveSnapshot $snapshot): string => $snapshot->id, $this->snapshots());
    }

    /** @return list<DriveRow> */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->snapshots() as $snapshot) {
            $quota = null;

            try {
                $quota = $this->handle($snapshot->id)?->quota();
            } catch (VaulterException) {
                // A drive that stopped answering contributes its snapshot without accounting.
            }

            $rows[] = [
                'id' => $snapshot->id,
                'name' => $snapshot->name,
                'owner_type' => $snapshot->ownerType,
                'owner_id' => $snapshot->ownerId,
                'profile' => $snapshot->profile,
                'status' => $snapshot->status,
                'used_bytes' => $quota->usedBytes ?? 0,
                'quota_bytes' => $quota?->limitBytes,
                'created_at' => CarbonImmutable::instance($snapshot->createdAt),
            ];
        }

        return $rows;
    }

    /** @return list<DriveSnapshot> */
    private function snapshots(): array
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return [];
        }

        $found = [];
        $cursor = null;

        do {
            $page = Vaulter::drives($actor, new ListOptions(perPage: self::PAGE, cursor: $cursor));
            foreach ($page as $snapshot) {
                $found[] = $snapshot;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $found;
    }
}
