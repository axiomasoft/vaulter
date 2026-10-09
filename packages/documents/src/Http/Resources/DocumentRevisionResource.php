<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Support\DocumentTypeKey;

/**
 * The read shape of a document checkpoint (§2.5 / M4a) — or of a document's working
 * state, rendered with the same keys until REST v1: the working state reads as its
 * head checkpoint, with the live content, hash and Yjs size, and is_autosave=true
 * while it runs ahead of that checkpoint. The binary yjs_state is NOT serialised
 * (only its size) — it travels through the collaboration transport.
 *
 * @mixin DocumentRevisionSnapshot
 */
final class DocumentRevisionResource extends JsonResource
{
    /**
     * Whether to include the full content (the single-document fetch); the history
     * listing omits it to stay light.
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

        if ($resource instanceof DocumentSnapshot) {
            return $this->workingState($resource);
        }

        /** @var DocumentRevisionSnapshot $revision */
        $revision = $resource;

        return [
            'id' => $revision->id,
            'node_id' => $revision->nodeId,
            'version_no' => $revision->number,
            'format' => DocumentTypeKey::toFormat($revision->type),
            'snapshot_label' => $revision->label,
            'is_autosave' => $revision->isAutosave,
            'keep_forever' => $revision->keepForever,
            // Checkpoints are written whole in one transaction; there is no other state.
            'status' => 'committed',
            'content_hash' => $revision->contentHash,
            'state_version' => $revision->stateVersion,
            'yjs_state_size' => $revision->yjsStateSize,
            'created_by' => $revision->createdBy,
            'created_at' => SnapshotValue::iso($revision->createdAt),
            'content' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workingState(DocumentSnapshot $document): array
    {
        $head = $document->revision;
        $ahead = $document->dirty;

        return [
            'id' => $document->headRevisionId,
            'node_id' => $document->nodeId,
            'version_no' => $head?->number,
            'format' => DocumentTypeKey::toFormat($document->type),
            'snapshot_label' => $ahead ? null : $head?->label,
            'is_autosave' => $ahead || ($head !== null && $head->isAutosave),
            'keep_forever' => !$ahead && $head !== null && $head->keepForever,
            'status' => 'committed',
            'content_hash' => $document->contentHash,
            // Sent back as the autosave's state_version, it refuses a lost update (412).
            'state_version' => $document->stateVersion,
            'yjs_state_size' => $document->yjsStateSize,
            'created_by' => $document->updatedBy,
            'created_at' => SnapshotValue::iso($document->updatedAt),
            'content' => $this->withContent ? $this->readableContent($document->content) : null,
        ];
    }

    /**
     * The content as the CLIENT may safely mount it (VLT-REMED P5.2 / R-12).
     *
     * This resource is the primary read path — GET nodes/{node}/content feeds the editor
     * component directly. Both XSS echelons (D4) miss exactly here: the store-time
     * validator never saw the rows written before it existed, and the HTML sanitizer
     * guards only TipTapRenderer::toHtml() (export/preview). So a legacy
     * `link.href="javascript:…"` would travel straight into the host's editor.
     *
     * Rich text arrives already decayed (unknown nodes/marks/attribute keys stripped);
     * its URL attributes are scheme-scrubbed here. A non-rich-text handler's opaque
     * snapshot (a Univer workbook, §7 level 2) is returned verbatim.
     *
     * @return array<string, mixed>
     */
    private function readableContent(DocumentContent $content): array
    {
        return $content instanceof TipTapContent ? $content->withSanitizedUrls()->toArray() : $content->toArray();
    }
}
