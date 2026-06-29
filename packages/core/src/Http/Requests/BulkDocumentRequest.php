<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Validation\Rule;
use Vaulter\Enums\BulkAction;

final class BulkDocumentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(BulkAction::values())],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
            'folder_id' => ['nullable'],
        ];
    }

    public function action(): BulkAction
    {
        return $this->enum('action', BulkAction::class) ?? BulkAction::Delete;
    }

    /**
     * @return list<int|string>
     */
    public function ids(): array
    {
        $ids = $this->input('ids');

        if (!is_array($ids)) {
            return [];
        }

        $result = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                $result[] = $id;
            } elseif (is_scalar($id)) {
                $result[] = (string) $id;
            }
        }

        return $result;
    }

    public function folderId(): int|string|null
    {
        return $this->nullableScalarId('folder_id');
    }
}
