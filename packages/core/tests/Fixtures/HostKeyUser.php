<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class HostKeyUser extends User
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;
}
