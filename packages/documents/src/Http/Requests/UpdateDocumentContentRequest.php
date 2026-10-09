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
 * raw bytes here. state_version is the optional lost-update precondition.
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
            'state_version' => ['nullable', 'integer', 'min:0'],
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
     * The state_version the client last read, or null to save without the precondition.
     */
    public function expectedStateVersion(): ?int
    {
        $version = $this->input('state_version');

        // Validated as a nonnegative integer: JSON sends an int, a form a digit string.
        return is_int($version) || (is_string($version) && ctype_digit($version)) ? (int) $version : null;
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
