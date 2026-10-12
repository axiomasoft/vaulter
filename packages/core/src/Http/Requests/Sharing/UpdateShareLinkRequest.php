<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Data\Sharing\ShareLinkUpdate;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validates a share-link update: expiry, use budget, password and
 * audience. A field that is absent stays as it is; `password: null` and
 * `max_uses: null` remove the password and the budget. The token, the role and
 * the scope cannot be changed here.
 */
final class UpdateShareLinkRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(SharingConfig $sharingConfig): array
    {
        return [
            'audience' => ['sometimes', Rule::enum(ShareAudience::class)],
            'password' => ['sometimes', 'nullable', 'string', 'min:1', 'max:255'],
            'expires_at' => [
                'sometimes',
                'date',
                'after:now',
                'before_or_equal:'.Carbon::now()->addHours($sharingConfig->maxTtlHours())->toDateTimeString(),
            ],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(): ShareLinkUpdate
    {
        $expiresAt = $this->input('expires_at');
        $password = $this->input('password');
        $maxUses = $this->input('max_uses');
        $audience = $this->input('audience');

        return new ShareLinkUpdate(
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
            password: is_string($password) && $password !== '' ? $password : null,
            maxUses: is_numeric($maxUses) ? (int) $maxUses : null,
            audience: is_string($audience) ? ShareAudience::from($audience) : null,
            removePassword: $this->has('password') && $password === null,
            removeMaxUses: $this->has('max_uses') && $maxUses === null,
        );
    }
}
