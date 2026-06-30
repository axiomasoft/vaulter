<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures\Access;

use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Traits\HasRoles;

/**
 * A test actor wired with spatie's HasRoles, so the SpatiePermissionResolver tests
 * can assign roles/permissions (team-scoped) and assert the driver resolves them.
 * Lives in tests/ — spatie/laravel-permission is a require-dev dependency only.
 */
final class SpatieUser extends User
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Pin the guard so spatie resolves roles/permissions under a single, known guard
     * in the thin test app (no host auth config).
     */
    protected string $guard_name = 'web';
}
