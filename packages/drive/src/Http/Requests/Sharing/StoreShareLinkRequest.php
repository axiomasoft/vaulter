<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Configuration\SharingConfig;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a create-share-link request and assembles its
 * ShareLinkInput command. The role key, audience, scope, optional password, expiry
 * and use budget are host-supplied; the controller authorizes Ability::Share on the
 * node first. Which roles a link may carry for which audience is decided by the
 * Action (share_role_not_allowed), so the one rule table lives in one place.
 *
 * role defaults to reader and audience to anyone. An EXPLICIT `expires_at` is bounded
 * above by `$sharingConfig->maxTtlHours()` and by the drive
 * profile's maxShareLinkTtlDays (checked by the Action) — an omitted `expires_at`
 * defaults to `$sharingConfig->defaultTtlHours()` in {@see CreateShareLinkAction},
 * which this request never sees.
 */
#[Internal]
final class StoreShareLinkRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(SharingConfig $sharingConfig): array
    {
        return [
            'role' => ['nullable', 'string', Rule::in((new RoleRegistry)->keys())],
            'audience' => ['nullable', Rule::enum(ShareAudience::class)],
            'scope' => ['nullable', Rule::enum(GrantScope::class)],
            'password' => ['nullable', 'string', 'min:1', 'max:255'],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before_or_equal:'.Carbon::now()->addHours($sharingConfig->maxTtlHours())->toDateTimeString(),
            ],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(): ShareLinkInput
    {
        $expiresAt = $this->input('expires_at');
        $password = $this->input('password');
        $maxUses = $this->input('max_uses');

        return new ShareLinkInput(
            role: $this->string('role', RoleRegistry::READER)->toString(),
            audience: ShareAudience::from($this->string('audience', ShareAudience::Anyone->value)->toString()),
            scope: GrantScope::from($this->string('scope', GrantScope::Item->value)->toString()),
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
            password: is_string($password) && $password !== '' ? $password : null,
            maxUses: is_numeric($maxUses) ? (int) $maxUses : null,
        );
    }
}
