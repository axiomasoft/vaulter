<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ScanGenerator;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\Surface;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Surface.php';

/** I13 against tests/Repo/Arch/Fixtures/allowlists/i13.json (report mode until P7). */
return new ScanGenerator(Surface::INVARIANT, static fn (Repo $repo): array => Surface::violations(Surface::read($repo), SourceIndex::forRepo($repo)));
