<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Support\Identity;

/**
 * A drive by its id, as a host names one on the way in: from a snapshot, a handle or the bare id. Vaulter loads
 * the drive itself; the reference carries nothing else.
 */
#[Api]
final readonly class DriveRef
{
    private function __construct(public string $id) {}

    public static function of(DriveSnapshot|DriveHandle|int|string $drive): self
    {
        $id = match (true) {
            $drive instanceof DriveSnapshot => $drive->id,
            $drive instanceof DriveHandle => $drive->snapshot()->id,
            default => Identity::stringify($drive),
        };
        if ($id === '') {
            throw new InvalidArgumentException('[Vaulter] A drive reference needs an id.');
        }

        return new self($id);
    }
}
