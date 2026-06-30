<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Drive;

use Illuminate\Http\UploadedFile;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Support\Config;

/**
 * Validates a multipart upload (§2.4): the binary file plus an optional
 * destination parent, an optional stored-name override, and an optional target
 * node id for a new-version upload onto an existing file node.
 *
 * Unlike the move/rename/copy commands, the upload command (UploadFileData) is
 * assembled by DriveContext::upload()/uploadVersion() — they own the stream
 * normalisation that keeps the Action free of HTTP/PSR types — so this Request
 * exposes the validated file + targets and the controller delegates to the
 * DriveContext rather than building the DTO here.
 */
final class UploadFileRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fileRules = ['required', 'file', 'max:'.Config::maxUploadKb()];

        // An empty allowlist stays permissive (the default); a configured list
        // restricts the upload to that mime set via Laravel's `mimetypes` rule.
        $allowedMimes = Config::allowedUploadMimes();

        if ($allowedMimes !== []) {
            $fileRules[] = 'mimetypes:'.implode(',', $allowedMimes);
        }

        return [
            'file' => $fileRules,
            'parent_id' => ['nullable'],
            'node_id' => ['nullable'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
        ];
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

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    /**
     * The existing file node to commit a NEW VERSION onto (§2.4); null = a
     * brand-new file upload.
     */
    public function targetNodeId(): int|string|null
    {
        return $this->nullableScalarId('node_id');
    }

    /**
     * Whether this upload targets an existing file node (new-version path).
     */
    public function isNewVersion(): bool
    {
        return $this->targetNodeId() !== null;
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
