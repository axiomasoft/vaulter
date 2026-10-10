<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Vaulter\Http\Requests\VaulterFormRequest;

/** GET drives/{drive}/nodes: the folder to list (the drive root when absent); paging is PageResponse's. */
final class ListChildrenRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    public function parent(): ?string
    {
        $value = $this->query('parent');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
