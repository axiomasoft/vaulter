<?php

declare(strict_types=1);

namespace Vaulter\Permissions\Azgard\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use UnitEnum;
use Vaulter\Permissions\Azgard\Contracts\AzgardBackedUser;

/**
 * A controllable stand-in for the host's AzGuard-backed User: it IS an Eloquent user
 * (so it can be the Vaulter actor / Subject) AND an {@see AzgardBackedUser} whose three
 * AzGuard methods return configured results and RECORD their arguments — so a test can
 * both drive the real Vaulter resolver through the adapter and assert exactly what the
 * adapter forwarded (permission keys, panel id, the narrowed entity).
 *
 * This substitutes ONLY the leaf User (a host concern); everything above it — the
 * AzgardGuardAdapter and the Vaulter AzgardPermissionResolver — is the real code.
 */
final class SpyAzgardUser extends User implements AzgardBackedUser
{
    public $timestamps = false;

    protected $guarded = [];

    /** @var list<array{permission: string|UnitEnum, entity: Model, panelId: ?string}> */
    public array $scopedCalls = [];

    /** @var list<array{contextType: string, contextId: int|string, permission: string|UnitEnum, panelId: ?string}> */
    public array $contextCalls = [];

    /** @var list<array{permission: string|UnitEnum, panelId: ?string}> */
    public array $globalCalls = [];

    /** @var list<string> Permission keys the user holds scoped to an entity (any entity). */
    private array $scopedGrants = [];

    /** @var array<string, list<string>> "$type:$id" => granted permission keys in that workspace. */
    private array $workspaceGrants = [];

    /** @var list<string> Permission keys the user holds globally (e.g. '*' for super-admin). */
    private array $globalGrants = [];

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
    public function grantScoped(array $permissions): self
    {
        $this->scopedGrants = array_merge($this->scopedGrants, $permissions);

        return $this;
    }

    /**
     * @param list<string> $permissions
     */
    public function grantInWorkspace(string $type, int|string $id, array $permissions): self
    {
        $this->workspaceGrants[$type.':'.$id] = array_merge(
            $this->workspaceGrants[$type.':'.$id] ?? [],
            $permissions,
        );

        return $this;
    }

    /**
     * @param list<string> $permissions
     */
    public function grantGlobal(array $permissions): self
    {
        $this->globalGrants = array_merge($this->globalGrants, $permissions);

        return $this;
    }

    public function hasScopedPermission(string|UnitEnum $permission, Model $entity, ?string $panelId = null): bool
    {
        $this->scopedCalls[] = ['permission' => $permission, 'entity' => $entity, 'panelId' => $panelId];

        return in_array($this->key($permission), $this->scopedGrants, strict: true);
    }

    public function hasPermissionIn(
        string $contextType,
        int|string $contextId,
        string|UnitEnum $permission,
        ?string $panelId = null,
    ): bool {
        $this->contextCalls[] = [
            'contextType' => $contextType,
            'contextId' => $contextId,
            'permission' => $permission,
            'panelId' => $panelId,
        ];

        $held = $this->workspaceGrants[$contextType.':'.$contextId] ?? [];

        return in_array($this->key($permission), $held, strict: true);
    }

    public function hasPermission(string|UnitEnum $permission, ?string $panelId = null): bool
    {
        $this->globalCalls[] = ['permission' => $permission, 'panelId' => $panelId];

        return in_array($this->key($permission), $this->globalGrants, strict: true);
    }

    private function key(string|UnitEnum $permission): string
    {
        return $permission instanceof UnitEnum ? $permission->name : $permission;
    }
}
