<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Validation\Rule;
use Vaulter\Data\CreateShareData;
use Vaulter\Enums\Access\PermissionLevel;

final class StoreShareRequest extends VaulterFormRequest
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
