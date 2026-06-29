<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Validation\Rule;
use Vaulter\Data\GrantPermissionData;
use Vaulter\Enums\PermissionLevel;

final class StorePermissionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string'],
            'subject_id' => ['required'],
            'permission' => ['required', 'string', Rule::enum(PermissionLevel::class)],
        ];
    }

    public function toData(): GrantPermissionData
    {
        return new GrantPermissionData(
            subjectType: $this->string('subject_type')->toString(),
            subjectId: $this->scalarId('subject_id'),
            level: PermissionLevel::from($this->string('permission')->toString()),
            grantedBy: $this->actorId(),
        );
    }
}
