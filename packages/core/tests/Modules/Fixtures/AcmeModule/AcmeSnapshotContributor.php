<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Vaulter\Contracts\Drive\NodeSnapshotContributor;

/**
 * Tries to add its own fragment to the snapshots of a page. The snapshot has no place for a fragment of a
 * third-party module: the mapper refuses the key, it neither drops the fragment silently nor lets it through.
 */
final class AcmeSnapshotContributor implements NodeSnapshotContributor
{
    public static int $calls = 0;

    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function key(): string
    {
        return 'acme';
    }

    public function contribute(array $nodes): array
    {
        self::$calls++;

        return [];
    }
}
