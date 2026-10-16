<?php

declare(strict_types=1);

namespace Vaulter\Media\Doctor;

use Vaulter\Attributes\Internal;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Profiles\ProfileRegistry;

/** The disks a Vaulter install stores content on: the media default and each profile's own. */
#[Internal]
final class DiskSet
{
    /** @return list<string> sorted, without duplicates */
    public static function of(MediaConfig $config, ProfileRegistry $profiles): array
    {
        $disks = [$config->disk()];
        foreach ($profiles->all() as $profile) {
            $disks[] = $profile->getDisk() ?? $config->disk();
        }
        $disks = array_values(array_unique($disks));
        sort($disks, SORT_STRING);

        return $disks;
    }
}
