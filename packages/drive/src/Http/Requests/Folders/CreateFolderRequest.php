<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Folders;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Support\DriveNameRules;

/** POST drives/{drive}/folders: the folder name and its parent (the drive root when absent). */
#[Internal]
final class CreateFolderRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => DriveNameRules::rules(),
            'parent_id' => ['nullable', 'string', 'max:191'],
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
}
