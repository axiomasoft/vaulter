<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a create-share-link request (§2.7 / M2c) and assembles its
 * ShareLinkInput command. The permissions bitmask, level, scope, optional
 * password / require-login / discovery flags, expiry and use budget are
 * host-supplied; the controller authorizes Ability::Share on the node first.
 *
 * permissions defaults to READ (the safe minimum for a public link); the bitmask
 * is bounded to the permission range (0..63); anonymous Commenter shares are rejected. An EXPLICIT `expires_at` is bounded above
 * by `$sharingConfig->maxTtlHours()` (P1.2 / F-P1.3-03) — an omitted `expires_at`
 * defaults to `$sharingConfig->defaultTtlHours()` in {@see CreateShareLinkAction},
 * which this request never sees.
 */
final class StoreShareLinkRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(SharingConfig $sharingConfig): array
    {
        return [
            'permissions' => ['nullable', 'integer', 'min:0', 'max:'.Permission::all()],
            'level' => ['nullable', Rule::enum(GrantLevel::class)->except([GrantLevel::Commenter])],
            'scope' => ['nullable', Rule::enum(GrantScope::class)],
            'password' => ['nullable', 'string', 'min:1', 'max:255'],
            'require_login' => ['nullable', 'boolean'],
            'allow_discovery' => ['nullable', 'boolean'],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before_or_equal:'.Carbon::now()->addHours($sharingConfig->maxTtlHours())->toDateTimeString(),
            ],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(int|string|null $grantedBy): ShareLinkInput
    {
        $expiresAt = $this->input('expires_at');
        $password = $this->input('password');
        $maxUses = $this->input('max_uses');

        return new ShareLinkInput(
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
