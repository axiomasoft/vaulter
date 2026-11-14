<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\RealAzGuard\Stubs;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Foundation\Auth\User;
use Vaulter\AzGuard\Contracts\AzGuardBackedUser;

/**
 * A REAL AzGuard v1 subject — the exact shape a host declares: the AzGuard trait
 * supplies every method, and `implements AzGuardBackedUser` (which is AzGuard's own
 * `AzGuardSubject`) is the only bridge-specific line. Proves the "free implementation" claim.
 */
final class AzGuardUser extends User implements AzGuardBackedUser
{
    use HasAzGuard;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
