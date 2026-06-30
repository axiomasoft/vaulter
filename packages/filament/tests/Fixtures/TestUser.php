<?php

declare(strict_types=1);

namespace Vaulter\Filament\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * The acting panel admin for the Filament resource tests — the grant subject and
 * the actor the AccessResolver answers can() for.
 */
final class TestUser extends User
{
    protected $guarded = [];

    public $timestamps = false;
}
