<?php

declare(strict_types=1);

use Vaulter\Inventory\Identities\Http;
use Vaulter\Inventory\IdentityGenerator;
use Vaulter\Inventory\Repo;

require_once dirname(__DIR__).'/lib/Identities/Http.php';

/** tests/Repo/Arch/Fixtures/identities/http.json (owners: identities/owners/http.json). */
return new IdentityGenerator('http', static fn (Repo $repo): array => Http::build($repo));
