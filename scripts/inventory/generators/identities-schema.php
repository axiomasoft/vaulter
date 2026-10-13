<?php

declare(strict_types=1);

use Vaulter\Inventory\Identities\Schema;
use Vaulter\Inventory\IdentityGenerator;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/lib/Identities/Schema.php';

/** tests/Repo/Arch/Fixtures/identities/schema.json (owners: identities/owners/schema.json). */
return new IdentityGenerator('schema', static fn (Repo $repo): array => Schema::build($repo));
