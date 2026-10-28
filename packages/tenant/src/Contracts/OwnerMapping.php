<?php

declare(strict_types=1);

namespace Vaulter\Tenant\Contracts;

use Illuminate\Database\Eloquent\Model;
use Tenant\Data\IsolationIdentity;

/** Host re-reads the owning record/control-plane mapping; never return the ambient selection. */
interface OwnerMapping
{
    public function identity(Model $owner): IsolationIdentity;
}
