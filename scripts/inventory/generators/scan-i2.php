<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\DependencyDirection;
use Vaulter\Inventory\Scans\ScanContext;
use Vaulter\Inventory\Scans\ScanGenerator;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Scans/DependencyDirection.php';

/** Against tests/Repo/Arch/Fixtures/allowlists/i2.json. */
return new ScanGenerator(DependencyDirection::INVARIANT, static fn (Repo $repo): array => DependencyDirection::found(ScanContext::forRepo($repo)));
