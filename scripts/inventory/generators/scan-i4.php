<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ImplicitActivation;
use Vaulter\Inventory\Scans\ScanContext;
use Vaulter\Inventory\Scans\ScanGenerator;

require_once dirname(__DIR__).'/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__).'/lib/Scans/ImplicitActivation.php';

/** Against tests/Repo/Arch/Fixtures/allowlists/i4.json. */
return new ScanGenerator(ImplicitActivation::INVARIANT, static fn (Repo $repo): array => ImplicitActivation::found(ScanContext::forRepo($repo)));
