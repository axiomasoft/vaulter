<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\Data\CreateShareData;
use DocVault\Enums\PermissionLevel;
use Illuminate\Validation\Rule;

final class StoreShareRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission' => ['required', 'string', Rule::in(['view', 'comment', 'edit'])],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function toData(): CreateShareData
    {
        return new CreateShareData(
            level: PermissionLevel::from($this->string('permission')->toString()),
            expiresAt: $this->date('expires_at'),
            createdBy: $this->actorId(),
        );
    }
}
