<?php

declare(strict_types=1);

use Vaulter\Inventory\Identities\Protocol;
use Vaulter\Inventory\IdentityGenerator;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/lib/Identities/Protocol.php';

/** tests/Repo/Arch/Fixtures/identities/protocol.json (owners: identities/owners/protocol.json). */
return new IdentityGenerator('protocol', static fn (Repo $repo): array => Protocol::build($repo));
