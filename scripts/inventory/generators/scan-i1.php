<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\CoreIsolation;
use Vaulter\Inventory\Scans\ScanContext;
use Vaulter\Inventory\Scans\ScanGenerator;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Scans/CoreIsolation.php';

/** I1 against tests/Repo/Arch/Fixtures/allowlists/i1.json. */
return new ScanGenerator(CoreIsolation::INVARIANT, static fn (Repo $repo): array => CoreIsolation::found(ScanContext::forRepo($repo)));
