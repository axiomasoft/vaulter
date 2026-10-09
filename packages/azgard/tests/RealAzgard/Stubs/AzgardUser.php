<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\RealAzgard\Stubs;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Foundation\Auth\User;
use Vaulter\Azgard\Contracts\AzgardBackedUser;

/**
 * A REAL AzGuard v1 subject — the exact shape a host declares: the AzGuard trait
 * supplies every method, and `implements AzgardBackedUser` (which is AzGuard's own
 * `AzGuardSubject`) is the only bridge-specific line. Proves the "free implementation" claim.
 */
final class AzgardUser extends User implements AzgardBackedUser
{
    use HasAzGuard;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
