<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ForeignKeys;
use Vaulter\Inventory\Scans\ScanGenerator;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Scans/ForeignKeys.php';

/** I3 (the `reverse` foreign keys of the fresh fk-map) against tests/Repo/Arch/Fixtures/allowlists/i3.json. */
return new ScanGenerator(ForeignKeys::INVARIANT, static fn (Repo $repo): array => ForeignKeys::found(ForeignKeys::map($repo)));
