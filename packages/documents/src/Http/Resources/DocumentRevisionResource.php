<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\ValueObjects\TipTapContent;

/**
 * The read shape of a document checkpoint (§2.5 / M4a) — or of a document's working
 * state, rendered with the same keys until REST v1: the working state reads as its
 * head checkpoint, with the live content, hash and Yjs size, and is_autosave=true
 * while it runs ahead of that checkpoint. The binary yjs_state is NOT serialised
 * (only its size) — it travels through the collaboration transport.
 *
 * @mixin DocumentRevision
 */
final class DocumentRevisionResource extends JsonResource
{
    /**
     * Whether to include the full TipTap content (the single-version fetch); the
     * history listing omits it to stay light.
     */
    private bool $withContent = false;

    public function withContent(bool $with = true): self
    {
        $this->withContent = $with;

        return $this;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        if ($this->withContent) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof Document) {
            return $this->workingState($resource);
        }

        /** @var DocumentRevision $revision */
        $revision = $resource;
        $document = $revision->document;

        return [
            'id' => $revision->getKey(),
            'node_id' => $document->node_id,
            'version_no' => $revision->number,
            'format' => $document->legacyFormat(),
            'snapshot_label' => $revision->label,
            'is_autosave' => $revision->is_autosave,
            'keep_forever' => $revision->keep_forever,
            'status' => $revision->status,
            'content_hash' => $revision->content_hash,
            'yjs_state_size' => $revision->yjs_state_size,
            'created_by' => $revision->created_by,
            'created_at' => $revision->created_at,
            'content' => $this->withContent ? $this->readableContent($revision->content, $revision->tipTapContent(...)) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workingState(Document $document): array
    {
        $head = $document->currentRevision;
        $ahead = $document->hasUncheckpointedChanges();

        return [
            'id' => $document->current_revision_id,
            'node_id' => $document->node_id,
            'version_no' => $head?->number,
            'format' => $document->legacyFormat(),
            'snapshot_label' => $ahead ? null : $head?->label,
            'is_autosave' => $ahead || ($head !== null && $head->is_autosave),
            'keep_forever' => !$ahead && $head !== null && $head->keep_forever,
            'status' => 'committed',
            'content_hash' => $document->content_hash,
            'yjs_state_size' => $document->yjs_state_size,
            'created_by' => $document->updated_by,
            'created_at' => $document->updated_at,
            'content' => $this->withContent ? $this->readableContent($document->content, $document->tipTapContent(...)) : null,
        ];
    }

    /**
     * The content as the CLIENT may safely mount it (VLT-REMED P5.2 / R-12).
     *
     * This resource is the primary read path — GET nodes/{node}/content feeds the editor
     * component directly — and it used to return the RAW persisted row. Both XSS echelons
     * (D4) miss exactly here: the store-time validator never saw the rows written before it
     * existed, and the HTML sanitizer guards only TipTapRenderer::toHtml() (export/preview).
     * So a legacy `link.href="javascript:…"` travelled straight into the host's editor and
     * into the DOM as an <a href>.
     *
     * TipTap content is therefore DECAYED (unknown nodes/marks/attribute keys stripped — the
     * same contract {@see DocumentRevision::tipTapContent()} promises) and its URL
     * attributes are scheme-scrubbed. A non-rich-text handler's opaque snapshot (a Univer
     * workbook, §7 level 2) is NOT a TipTap doc and is still returned verbatim — the shape,
     * not the `format` label, decides.
     *
     * @param  array<string, mixed>|null $content
     * @param  \Closure(): TipTapContent $decay
     * @return array<string, mixed>|null
     */
    private function readableContent(?array $content, \Closure $decay): ?array
    {
        if (!is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return $content;
        }

        return $decay()->withSanitizedUrls()->toArray();
    }
}
