<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Vaulter\Data\CreateVersionData;

final class StoreVersionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'snapshot_label' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): CreateVersionData
    {
        return new CreateVersionData(
            snapshotLabel: $this->has('snapshot_label') ? $this->string('snapshot_label')->toString() : null,
            actorId: $this->actorId(),
        );
    }
}
