<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Packman\Attributes\Internal;
use Vaulter\Drive\Support\DriveNameRules;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * POST drives/{drive}/documents: the document's name, its type key (`vendor/name`; the
 * configured default when absent), its parent (the drive root when absent) and the
 * initial content. The type's codec validates the content and an unregistered type is
 * refused by the operation, so this request only checks the shape.
 */
#[Internal]
final class CreateDocumentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => DriveNameRules::rules(),
            'parent_id' => ['nullable', 'string', 'max:191'],
            'type' => ['nullable', 'string', 'regex:/^[a-z0-9-]+\/[a-z0-9-]+$/'],
            'content' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function parentId(): ?string
    {
        $value = $this->input('parent_id');

        return is_string($value) ? $value : null;
    }

    public function typeKey(): ?string
    {
        $type = $this->input('type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * The raw initial content; null starts with the type's default.
     *
     * @return array<string, mixed>|null
     */
    public function content(): ?array
    {
        return $this->nullableArrayInput('content');
    }
}
