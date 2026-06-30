<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Uploads;

use Vaulter\Enums\Uploads\UploadProtocol;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Validate a resumable-upload session create (§2.8 / D16 / M3a): the protocol, the
 * optional targets (a new-file destination parent + stored name, OR a new-version
 * node id) and the declared total size / mime / metadata. The client-DECLARED
 * sha256 is accepted but never trusted as the content address — BlobStore.commit
 * re-hashes the assembled bytes (H.3).
 *
 * The Action command (UploadInitData) is assembled by the controller (it resolves the
 * route-bound storage / target node and decodes the tus Upload-Metadata header), so
 * this Request only validates + exposes the scalar inputs.
 */
final class CreateUploadSessionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'protocol' => ['nullable', 'string', 'in:'.implode(',', UploadProtocol::values())],
            'parent_id' => ['nullable'],
            'node_id' => ['nullable'],
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

    public function parentId(): int|string|null
    {
        return $this->nullableScalarId('parent_id');
    }

    /**
     * The existing file node to commit a NEW VERSION onto (§2.8); null = a brand-new
     * file upload.
     */
    public function targetNodeId(): int|string|null
    {
        return $this->nullableScalarId('node_id');
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
}
