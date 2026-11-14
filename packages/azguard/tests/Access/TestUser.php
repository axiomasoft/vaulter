<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Access;

use Illuminate\Foundation\Auth\User;

final class TestUser extends User
{
    protected $guarded = [];

    public $timestamps = false;
}
