<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Packman\Attributes\Internal;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Media\Configuration\MediaOptions;

/** The disks a Vaulter install stores content on: the media default and each profile's own. */
#[Internal]
final class DiskSet
{
    /** @return list<string> sorted, without duplicates */
    public static function of(MediaOptions $options, ProfileRegistry $profiles): array
    {
        $disks = [$options->disk];
        foreach ($profiles->all() as $profile) {
            $disks[] = $profile->getDisk() ?? $options->disk;
        }
        $disks = array_values(array_unique($disks));
        sort($disks, SORT_STRING);

        return $disks;
    }
}
