<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\Documents\Documents\DocumentFileDescriptorFactory;
use Vaulter\Documents\Documents\DocumentHandlerRegistry;
use Vaulter\Documents\Documents\Handlers\UniverHandler;
use Vaulter\Documents\Enums\Capability;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Documents\OpaqueDocumentContent;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * Validates a document-content autosave PATCH (§2.5 / M4a). The content SHAPE follows
 * the node's resolved document handler: a RICH-TEXT handler (the default) requires a
 * TipTap doc ({"type":"doc"}); a non-rich-text handler (e.g. the spreadsheet
 * {@see UniverHandler}) accepts an opaque JSON snapshot
 * the core stores verbatim. yjs_state is the optional base64-encoded binary Yjs CRDT
 * update (the M4b realtime source of truth) — decoded back to raw bytes here.
 */
final class UpdateDocumentContentRequest extends VaulterFormRequest
{
    private ?bool $richText = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'content' => ['required', 'array'],
            'yjs_state' => ['nullable', 'string'],
        ];

        // Only a rich-text handler pins the TipTap {"type":"doc"} shape; a non-rich-text
        // handler's content is opaque and validated only as a JSON object.
        if ($this->isRichText()) {
            $rules['content.type'] = ['required', 'string', 'in:doc'];
        }

        return $rules;
    }

    /**
     * The typed content payload for the resolved handler — a validated TipTap doc for a
     * rich-text handler, or an opaque snapshot stored verbatim otherwise.
     */
    public function content(): DocumentContent
    {
        $raw = $this->arrayInput('content');

        return $this->isRichText()
            ? TipTapContent::fromArray($raw)
            : OpaqueDocumentContent::fromArray($raw);
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

    /**
     * Whether the route node's editable content is rich text (TipTap). Resolves the
     * node's handler and reads its capability; defaults to true (the historical shape)
     * when no node is bound. Memoised — the registry itself also caches the resolution.
     */
    private function isRichText(): bool
    {
        if ($this->richText !== null) {
            return $this->richText;
        }

        $node = $this->route('node');

        if (!$node instanceof Node) {
            return $this->richText = true;
        }

        $descriptor = app(DocumentFileDescriptorFactory::class)->forNode($node);
        $handler = app(DocumentHandlerRegistry::class)->resolve($descriptor, Capability::Edit);

        return $this->richText = $handler->capabilities($descriptor)->richText;
    }
}
