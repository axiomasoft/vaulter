<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\Data\CreateDocumentData;
use DocVault\Enums\DocumentType;
use DocVault\Support\Config;
use DocVault\ValueObjects\TipTapContent;
use Illuminate\Validation\Rule;

final class StoreDocumentRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_type' => ['required', 'string'],
            'workspace_id' => ['required'],
            'type' => ['required', 'string', Rule::in(Config::enabledDocumentTypes() ?: DocumentType::values())],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'folder_id' => ['nullable'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function toData(): CreateDocumentData
    {
        return new CreateDocumentData(
            workspaceType: $this->string('workspace_type')->toString(),
            workspaceId: $this->scalarId('workspace_id'),
            type: DocumentType::from($this->string('type')->toString()),
            title: $this->string('title')->toString(),
            content: TipTapContent::fromArray($this->arrayInput('content')),
            folderId: $this->nullableScalarId('folder_id'),
            meta: $this->nullableArrayInput('meta'),
            actorId: $this->actorId(),
        );
    }
}
