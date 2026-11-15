<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ScanGenerator;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\Surface;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Surface.php';

/** Blocks signature and extended surface leaks against the exact source-backed allowlist. */
return new ScanGenerator(Surface::INVARIANT, static function (Repo $repo): array {
    $classified = Surface::classify(Surface::read($repo), SourceIndex::forRepo($repo));

    return [...$classified['signature'], ...$classified['extended']];
});
