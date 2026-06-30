<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Vaulter\Data\CreateFolderData;

final class StoreFolderRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_type' => ['required', 'string'],
            'workspace_id' => ['required'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable'],
            'order' => ['nullable', 'integer'],
        ];
    }

    public function toData(): CreateFolderData
    {
        return new CreateFolderData(
            workspaceType: $this->string('workspace_type')->toString(),
            workspaceId: $this->scalarId('workspace_id'),
            name: $this->string('name')->toString(),
            parentId: $this->nullableScalarId('parent_id'),
            order: $this->integer('order'),
        );
    }
}
