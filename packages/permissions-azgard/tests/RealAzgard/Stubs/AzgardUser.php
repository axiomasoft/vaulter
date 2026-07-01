<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard\Tests\RealAzgard\Stubs;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasScopedRoles;
use Illuminate\Foundation\Auth\User;
use Vaulter\Permissions\Azgard\Contracts\AzgardBackedUser;

/**
 * A REAL AzGuard-backed user — the exact shape a host declares: the AzGuard traits
 * supply every method, and `implements AzgardBackedUser` (which composes AzGuard's own
 * contracts) is the only bridge-specific line. Proves the "free implementation" claim.
 */
final class AzgardUser extends User implements AzgardBackedUser
{
    use HasAzGuard;
    use HasScopedRoles;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
