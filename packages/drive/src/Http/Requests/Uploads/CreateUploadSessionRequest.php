<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Uploads;

use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * POST drives/{drive}/uploads: the protocol, the target (a parent folder and stored
 * name for a new file, or node_id for a new revision of an existing one) and the
 * declared size, mime and metadata. A declared sha256 is accepted but never trusted as
 * the content address: the assembled bytes are hashed again when they are committed.
 */
#[Internal]
final class CreateUploadSessionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'protocol' => ['nullable', 'string', 'in:'.implode(',', UploadProtocol::values())],
            'parent_id' => ['nullable', 'string', 'max:191'],
            'node_id' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
            'total_size_bytes' => ['nullable', 'integer', 'min:0'],
            'expected_sha256' => ['nullable', 'string', 'size:64'],
            'mime_type' => ['nullable', 'string', 'max:191'],
            'metadata' => ['nullable', 'array'],
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

    /**
     * The transport, defaulting to tus (the disk-agnostic resumable path).
     */
    public function protocol(): UploadProtocol
    {
        $value = $this->input('protocol');

        return is_string($value)
            ? (UploadProtocol::tryFrom($value) ?? UploadProtocol::Tus)
            : UploadProtocol::Tus;
    }

    public function parentId(): ?string
    {
        $value = $this->input('parent_id');

        return is_string($value) ? $value : null;
    }

    /**
     * The existing file node to commit a NEW VERSION onto; null = a brand-new
     * file upload.
     */
    public function targetNodeId(): ?string
    {
        $value = $this->input('node_id');

        return is_string($value) ? $value : null;
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function totalSizeBytes(): ?int
    {
        $value = $this->input('total_size_bytes');

        return is_numeric($value) ? (int) $value : null;
    }

    public function expectedSha256(): ?string
    {
        $value = $this->input('expected_sha256');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function mimeType(): ?string
    {
        $value = $this->input('mime_type');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The tus Upload-Metadata (decoded key→value map). Read from the body when the
     * client posts JSON; the controller merges the decoded Upload-Metadata header on
     * top for true tus clients.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $value = $this->input('metadata');

        if (!is_array($value)) {
            return [];
        }

        $metadata = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $metadata[$key] = (string) $item;
            }
        }

        return $metadata;
    }

    /**
     * The metadata stored on the session: the body's map with $overrides (the decoded
     * tus Upload-Metadata header) on top, keys outside the configured allowlist dropped
     * and long values cut, so the stored map stays bounded. An unknown key is dropped,
     * not refused: a tus client sending one vendor key must not fail its upload.
     *
     * @param  array<string, string> $overrides
     * @return array<string, string>
     */
    public function allowedMetadata(DriveOptions $config, array $overrides = []): array
    {
        $allowedKeys = $config->uploads->resumable->metadata->allowedKeys;
        $maxLength = $config->uploads->resumable->metadata->maxValueLength;
        $allowed = [];
        foreach ([...$this->metadata(), ...$overrides] as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $allowed[$key] = mb_substr($value, 0, $maxLength);
            }
        }

        return $allowed;
    }
}
