<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Documents\Models\NodeDocumentVersion;

/**
 * The read shape of a node-attached document version (§2.5 / M4a). Read-only — it
 * transforms an already-loaded NodeDocumentVersion, never queries. The binary
 * yjs_state is NOT serialised (only its size) — it is fetched through the
 * collaboration transport, not the JSON history.
 *
 * @mixin NodeDocumentVersion
 */
final class NodeDocumentVersionResource extends JsonResource
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

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeDocumentVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->getKey(),
            'node_id' => $version->node_id,
            'version_no' => $version->version_no,
            'format' => $version->format,
            'snapshot_label' => $version->snapshot_label,
            'is_autosave' => $version->is_autosave,
            'keep_forever' => $version->keep_forever,
            'status' => $version->status,
            'content_hash' => $version->content_hash,
            'yjs_state_size' => $version->yjs_state_size,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at,
            'content' => $this->withContent ? $this->readableContent($version) : null,
        ];
    }

    /**
     * The version's content as the CLIENT may safely mount it (VLT-REMED P5.2 / R-12).
     *
     * This resource is the primary read path — GET nodes/{node}/content feeds the editor
     * component directly — and it used to return the RAW persisted row. Both XSS echelons
     * (D4) miss exactly here: the store-time validator never saw the rows written before it
     * existed, and the HTML sanitizer guards only TipTapRenderer::toHtml() (export/preview).
     * So a legacy `link.href="javascript:…"` travelled straight into the host's editor and
     * into the DOM as an <a href>.
     *
     * TipTap content is therefore DECAYED (unknown nodes/marks/attribute keys stripped — the
     * same contract {@see NodeDocumentVersion::tipTapContent()} promises) and its URL
     * attributes are scheme-scrubbed. A non-rich-text handler's opaque snapshot (a Univer
     * workbook, §7 level 2) is NOT a TipTap doc and is still returned verbatim — the shape,
     * not the `format` label, decides, because an autosave row inherits the column default
     * regardless of the handler that wrote it.
     *
     * @return array<string, mixed>|null
     */
    private function readableContent(NodeDocumentVersion $version): ?array
    {
        $content = $version->content;

        if (!is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return $content;
        }

        return $version->tipTapContent()->withSanitizedUrls()->toArray();
    }
}
