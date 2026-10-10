<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Files;

use Illuminate\Http\UploadedFile;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * POST drives/{drive}/files: one multipart file, its parent folder (the drive root when
 * absent) and an optional stored name. A new revision of an existing file goes to
 * POST files/{node}/revisions instead.
 */
final class UploadFileRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(DriveConfig $driveConfig): array
    {
        return [
            'file' => self::fileRules($driveConfig),
            'parent_id' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ];
    }

    /**
     * The configured size cap and mime allowlist; an empty allowlist accepts any type.
     *
     * @return list<string>
     */
    public static function fileRules(DriveConfig $driveConfig): array
    {
        $rules = ['required', 'file'];
        $maxFileSize = $driveConfig->maxFileSize();
        if ($maxFileSize > 0) {
            $rules[] = 'max:'.($maxFileSize / 1024);
        }
        $allowedMimes = $driveConfig->allowedMimeTypes();
        if ($allowedMimes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $allowedMimes);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name must not contain path separators (/ or \\).',
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    public function parentId(): ?string
    {
        $value = $this->input('parent_id');

        return is_string($value) ? $value : null;
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
