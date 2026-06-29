<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

final class MoveDocumentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'folder_id' => ['present', 'nullable'],
        ];
    }

    public function folderId(): int|string|null
    {
        return $this->nullableScalarId('folder_id');
    }
}
