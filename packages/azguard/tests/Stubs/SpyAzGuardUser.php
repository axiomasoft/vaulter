<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Stubs;

use AzGuard\Concerns\Subjects\HasAzGuard;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use UnitEnum;
use Vaulter\AzGuard\Contracts\AzGuardBackedUser;

/**
 * A controllable stand-in for the host's AzGuard-backed User: it IS an Eloquent user
 * (so it can be the Vaulter actor / Subject) AND an {@see AzGuardBackedUser} whose checks
 * return configured results and RECORD their arguments — so a test can both drive the
 * real Vaulter resolver through the adapter and assert exactly what the adapter forwarded
 * (permission keys, the `on:` resource or assignment scope, the panel).
 *
 * The AzGuard v1 `HasAzGuard` trait supplies the `AzGuardSubject` surface; only the
 * decision the adapter uses (`hasPermission`) is overridden with behaviour — the rest
 * are never reached without a panel. A super-admin is modelled as AzGuard decides it:
 * every check is allowed. This substitutes ONLY the leaf
 * User (a host concern); the adapter and the Vaulter AzGuardPermissionDriver above it are
 * the real code.
 */
final class SpyAzGuardUser extends User implements AzGuardBackedUser
{
    use HasAzGuard;

    public $timestamps = false;

    protected $guarded = [];

    /** @var list<array{permission: string|UnitEnum, on: Model|AssignmentScopeRef|null, guard: string|null}> */
    public array $permissionCalls = [];

    /** @var list<string> Permission keys the user holds on any resource entity. */
    private array $entityGrants = [];

    /** @var array<string, list<string>> "$type:$id" => granted permission keys in that assignment scope. */
    private array $scopeGrants = [];

    private bool $superAdmin = false;

    public static function make(int|string $id = 1): self
    {
        $user = new self;
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    /**
     * @param list<string> $permissions
     */
    public function grantOnEntities(array $permissions): self
    {
        $this->entityGrants = array_merge($this->entityGrants, $permissions);

        return $this;
    }

    /**
     * @param list<string> $permissions
     */
    public function grantInWorkspace(string $type, int|string $id, array $permissions): self
    {
        $this->scopeGrants[$type.':'.$id] = array_merge($this->scopeGrants[$type.':'.$id] ?? [], $permissions);

        return $this;
    }

    public function makeSuperAdmin(): self
    {
        $this->superAdmin = true;

        return $this;
    }

    // ─── The decision the adapter actually forwards ──────────────────────────

    public function hasPermission(string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        $this->permissionCalls[] = ['permission' => $permission, 'on' => $on, 'guard' => $guard];
        $key = $permission instanceof UnitEnum ? $permission->name : $permission;

        return match (true) {
            $this->superAdmin => true,
            $on instanceof Model => in_array($key, $this->entityGrants, strict: true),
            $on instanceof AssignmentScopeRef => in_array($key, $this->scopeGrants[$on->key()] ?? [], strict: true),
            default => false,
        };
    }
}
