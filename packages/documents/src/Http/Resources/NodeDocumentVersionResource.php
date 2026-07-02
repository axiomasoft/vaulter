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
            // The raw stored content — a TipTap doc for rich-text handlers, or a handler's
            // opaque snapshot (e.g. a Univer workbook) verbatim. Returned as persisted so a
            // non-rich-text editor gets its own shape back, not a decayed TipTap projection.
            'content' => $this->withContent ? $version->content : null,
        ];
    }
}
