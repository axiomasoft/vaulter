<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Node;

/**
 * The read shape of a Drive node (§2.2 / §5): the unified folder|file|document
 * leaf the Vue client renders. Read-only — it transforms an already-loaded Node
 * and the optional children/breadcrumb collections handed to it, never queries.
 *
 * The `abilities` object is embedded NOW as a stable placeholder so the client
 * contract does not shift when M2 wires authorization: this slice ships an
 * all-true shape (endpoints run open behind the route middleware), and M2 fills
 * each flag from the AccessResolver against $request->user().
 *
 * @mixin Node
 */
final class NodeResource extends JsonResource
{
    /**
     * Direct children of this node, attached by NodeController::show when listing
     * a folder's contents alongside the node itself.
     *
     * @var Collection<int, Node>|null
     */
    private ?Collection $children = null;

    /**
     * The breadcrumb chain (root → this node), attached by NodeController::show.
     *
     * @var Collection<int, Node>|null
     */
    private ?Collection $breadcrumb = null;

    /**
     * Embed the direct children of this node in the response (the folder listing
     * a `show` may return alongside the node).
     *
     * @param Collection<int, Node> $children
     */
    public function withChildren(Collection $children): self
    {
        $this->children = $children;

        return $this;
    }

    /**
     * Embed the breadcrumb chain (root first, this node last) in the response.
     *
     * @param Collection<int, Node> $breadcrumb
     */
    public function withBreadcrumb(Collection $breadcrumb): self
    {
        $this->breadcrumb = $breadcrumb;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var Node $node */
        $node = $this->resource;

        return [
            'id' => $node->getKey(),
            'kind' => $node->kind->value,
            'name' => $node->name,
            'mime' => $node->mime_type,
            'extension' => $node->extension,
            'size_bytes' => $node->size_bytes,
            'storage_id' => $node->storage_id,
            'parent_id' => $node->parent_id,
            'file_id' => $node->file_id,
            'current_version_id' => $node->current_version_id,
            'is_pinned' => $node->is_pinned,
            'position' => $node->position,
            'trashed_at' => $node->trashed_at,
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
            'children' => $this->children === null
                ? null
                : self::collection($this->children),
            'breadcrumb' => $this->breadcrumb === null
                ? null
                : self::collection($this->breadcrumb),
            'abilities' => $this->abilities(),
        ];
    }

    /**
     * The ability shape the Vue client binds against. M2 replaces these literals
     * with AccessResolver decisions for $request->user() on this node; until then
     * every endpoint is open behind the route middleware and the shape advertises
     * that.
     *
     * @return array<string, bool>
     */
    private function abilities(): array
    {
        // M2: populate from the AccessResolver — view/edit/move/copy/trash/restore/
        // delete/upload/rename for the authenticated actor on this node.
        return [
            'view' => true,
            'rename' => true,
            'move' => true,
            'copy' => true,
            'upload' => true,
            'trash' => true,
            'restore' => true,
            'delete' => true,
            'download' => true,
        ];
    }
}
