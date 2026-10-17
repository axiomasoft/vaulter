<?php

declare(strict_types=1);

namespace Vaulter\Testing\Fixtures;

use Illuminate\Foundation\Auth\User;
use Vaulter\Attributes\Internal;

#[Internal]
final class TestUser extends User
{
    protected $guarded = [];

    public $timestamps = false;
}
