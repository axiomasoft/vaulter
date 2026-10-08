<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Documents\Content\TipTap\TipTapCodec;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Node;

/**
 * Validates a document-content autosave PATCH. The content is decoded by the
 * document's type in DocumentOperations; this request only pins the TipTap
 * {"type":"doc"} root for a TipTap-based type, so the 0.x validation error keeps its
 * field. yjs_state is the optional base64-encoded binary Yjs update — decoded back to
 * raw bytes here.
 */
final class UpdateDocumentContentRequest extends VaulterFormRequest
{
    private ?bool $tipTap = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'content' => ['required', 'array'],
            'yjs_state' => ['nullable', 'string'],
        ];

        if ($this->isTipTap()) {
            $rules['content.type'] = ['required', 'string', 'in:doc'];
        }

        return $rules;
    }

    /**
     * The raw content; the document's type decodes it.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->arrayInput('content');
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

    private function isTipTap(): bool
    {
        if ($this->tipTap !== null) {
            return $this->tipTap;
        }

        $node = $this->route('node');
        $type = $node instanceof Node ? Document::query()->where('node_id', $node->getKey())->value('type') : null;

        return $this->tipTap = is_string($type)
            && app(DocumentTypeRegistry::class)->find($type)?->codec() instanceof TipTapCodec;
    }
}
