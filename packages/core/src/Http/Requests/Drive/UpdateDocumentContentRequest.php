<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Drive;

use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\ValueObjects\TipTapContent;

/**
 * Validates a document-content autosave PATCH (§2.5 / M4a). content is the TipTap
 * JSON projection; yjs_state is the optional base64-encoded binary Yjs CRDT update
 * (the M4b realtime source of truth) — decoded back to raw bytes here.
 */
final class UpdateDocumentContentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'yjs_state' => ['nullable', 'string'],
        ];
    }

    public function content(): TipTapContent
    {
        return TipTapContent::fromArray($this->arrayInput('content'));
    }

    /**
     * The raw Yjs binary state, decoded from the base64 transport field, or null.
     */
    public function yjsState(): ?string
    {
        $encoded = $this->input('yjs_state');

        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, strict: true);

        return $decoded === false ? null : $decoded;
    }
}
