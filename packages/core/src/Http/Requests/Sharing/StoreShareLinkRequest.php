<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a create-share-link request (§2.7 / M2c) and assembles its
 * ShareLinkInput command. The ability bitmask, role key (level), scope, optional
 * password / require-login / discovery flags, expiry and use budget are
 * host-supplied; the controller authorizes Ability::Share on the node first.
 *
 * permissions defaults to the reader role (view + download); the bitmask is bounded
 * to the ability range; anonymous comment-capable shares are rejected. An EXPLICIT `expires_at` is bounded above
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
            'permissions' => ['nullable', 'integer', 'min:0', 'max:'.Permission::all(), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_numeric($value) && ((int) $value & Permission::Comment->value) !== 0) {
                    $fail('Anonymous shares cannot grant Comment.');
                }
            }],
            'level' => ['nullable', 'string', Rule::in(array_values(array_diff((new RoleRegistry)->keys(), [RoleRegistry::COMMENTER])))],
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

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $level = $this->input('level');
            if ($this->input('permissions') === null && is_string($level) && (new RoleRegistry)->has($level) && (new RoleRegistry)->latest($level)->allows(Ability::Comment)) {
                $validator->errors()->add('permissions', 'Anonymous shares cannot grant Comment. Supply explicit non-comment permissions.');
            }
        }];
    }

    public function toData(): ShareLinkInput
    {
        $expiresAt = $this->input('expires_at');
        $password = $this->input('password');
        $maxUses = $this->input('max_uses');

        return new ShareLinkInput(
            permissions: $this->resolvePermissions(),
            level: $this->string('level', RoleRegistry::READER)->toString(),
            scope: GrantScope::from($this->string('scope', GrantScope::Item->value)->toString()),
            password: is_string($password) && $password !== '' ? $password : null,
            requireLogin: $this->boolean('require_login'),
            allowDiscovery: $this->boolean('allow_discovery'),
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
            maxUses: is_numeric($maxUses) ? (int) $maxUses : null,
        );
    }

    /**
     * The bitmask to grant: the explicit permissions when supplied, else the
     * abilities of the chosen role, else the reader role.
     */
    private function resolvePermissions(): int
    {
        $permissions = $this->input('permissions');

        if (is_numeric($permissions)) {
            return (int) $permissions;
        }

        $level = $this->input('level');

        if (is_string($level) && $level !== '') {
            return (new RoleRegistry)->latest($level)->bits;
        }

        return (new RoleRegistry)->latest(RoleRegistry::READER)->bits;
    }
}
