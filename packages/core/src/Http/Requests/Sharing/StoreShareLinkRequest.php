<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Data\Sharing\CreateShareLinkData;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\GrantScope;
use Vaulter\Enums\Permission;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a create-share-link request (§2.7 / M2c) and assembles its
 * CreateShareLinkData command. The permissions bitmask, level, scope, optional
 * password / require-login / discovery flags, expiry and use budget are
 * host-supplied; the controller authorizes Ability::Share on the node first.
 *
 * permissions defaults to READ (the safe minimum for a public link); the bitmask
 * is bounded to the CRUDS range (0..31).
 */
final class StoreShareLinkRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['nullable', 'integer', 'min:0', 'max:'.Permission::allBits()],
            'level' => ['nullable', Rule::enum(GrantLevel::class)],
            'scope' => ['nullable', Rule::enum(GrantScope::class)],
            'password' => ['nullable', 'string', 'min:1', 'max:255'],
            'require_login' => ['nullable', 'boolean'],
            'allow_discovery' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(int|string|null $grantedBy): CreateShareLinkData
    {
        $expiresAt = $this->input('expires_at');
        $password = $this->input('password');
        $maxUses = $this->input('max_uses');

        return new CreateShareLinkData(
            permissions: $this->resolvePermissions(),
            level: GrantLevel::from($this->string('level', GrantLevel::Reader->value)->toString()),
            scope: GrantScope::from($this->string('scope', GrantScope::Item->value)->toString()),
            password: is_string($password) && $password !== '' ? $password : null,
            requireLogin: $this->boolean('require_login'),
            allowDiscovery: $this->boolean('allow_discovery'),
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
            maxUses: is_numeric($maxUses) ? (int) $maxUses : null,
            grantedBy: $grantedBy,
        );
    }

    /**
     * The bitmask to grant: the explicit permissions when supplied, else the
     * bitmask the chosen level confers, else plain READ. A public link defaults to
     * the smallest useful grant.
     */
    private function resolvePermissions(): int
    {
        $permissions = $this->input('permissions');

        if (is_numeric($permissions)) {
            return (int) $permissions;
        }

        $level = $this->input('level');

        if (is_string($level) && $level !== '') {
            return GrantLevel::from($level)->bits();
        }

        return Permission::Read->value;
    }
}
