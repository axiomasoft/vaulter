<?php

declare(strict_types=1);

use Vaulter\Inventory\Identities\Cli;
use Vaulter\Inventory\IdentityGenerator;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/lib/Identities/Cli.php';

/** tests/Repo/Arch/Fixtures/identities/cli.json (owners: identities/owners/cli.json). */
return new IdentityGenerator('cli', static fn (Repo $repo): array => Cli::build($repo));
