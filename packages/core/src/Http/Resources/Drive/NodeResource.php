<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Abilities\NodeAbilities;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\NodeHandlerResolver;
use Vaulter\Enums\NodeKind;
use Vaulter\Models\Node;

/**
 * The read shape of a Drive node (§2.2 / §5): the unified folder|file|document
 * leaf the Vue client renders. Read-only — it transforms an already-loaded Node
 * and the optional children/breadcrumb collections handed to it, never queries.
 *
 * The `abilities` object is filled by the AccessResolver (§2.6 / H.2) for the
 * request's actor, so the client renders only the actions that actor may perform
 * on this node. With no acting user every flag is false (a read-only shell).
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
            'abilities' => $this->abilities($request, $node),
            // The resolved format handler (§4.5 / H.5): id + capabilities + ui so the
            // Vue client routes the node to the right editor/preview/download surface.
            // A folder has no payload, so it carries no handler descriptor.
            'handler' => $this->handler($node),
        ];
    }

    /**
     * The handler descriptor for a leaf node (file/document/shortcut) — resolved
     * through the registry by the node's server-trusted mime/extension (H.5). Null
     * for a folder. Resolved lazily via the container so the Resource stays a pure
     * transform and only computes it when serialising a leaf.
     *
     * @return array<string, mixed>|null
     */
    private function handler(Node $node): ?array
    {
        if ($node->kind === NodeKind::Folder) {
            return null;
        }

        return app(NodeHandlerResolver::class)->describe($node)->toArray();
    }

    /**
     * The ability map the Vue client binds against, resolved through the
     * AccessResolver for the request's actor on this node (H.2). No acting user ⇒
     * an all-false map (a read-only shell), never a mis-advertised action.
     *
     * @return array<string, bool>
     */
    private function abilities(Request $request, Node $node): array
    {
        $user = $request->user();
        $actor = $user instanceof Authorizable ? $user : null;

        return NodeAbilities::for(
            actor: $actor,
            node: $node,
            resolver: app(AccessResolver::class),
        )->toArray();
    }
}
