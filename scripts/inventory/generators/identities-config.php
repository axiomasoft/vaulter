<?php

declare(strict_types=1);

use Vaulter\Inventory\Identities\Config;
use Vaulter\Inventory\IdentityGenerator;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/lib/Identities/Config.php';

/** tests/Repo/Arch/Fixtures/identities/config.json (owners: identities/owners/config.json). */
return new IdentityGenerator('config', static fn (Repo $repo): array => Config::build($repo));
