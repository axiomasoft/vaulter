<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Sharing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Node;

/**
 * The PUBLIC read shape of a node reached through a bearer share (§2.7 / M2c). It
 * is deliberately leaner than the owner-side NodeResource: it carries NO identity
 * "abilities" map (the share grants exactly its own bits — there is no per-actor
 * ability resolution here) and exposes only the metadata safe for an anonymous
 * holder of the link. It never queries — it transforms an already-loaded node and
 * an optional already-loaded children collection.
 *
 * @mixin Node
 */
final class SharedNodeResource extends JsonResource
{
    /**
     * The in-scope children to embed (the subtree listing), attached by the
     * controller. Null on a plain show.
     *
     * @var Collection<int, Node>|null
     */
    private ?Collection $children = null;

    /**
     * Embed the in-scope children (a scope=subtree listing) in the response.
     *
     * @param Collection<int, Node> $children
     */
    public function withChildren(Collection $children): self
    {
        $this->children = $children;

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
            'parent_id' => $node->parent_id,
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
            'children' => $this->children === null
                ? null
                : self::collection($this->children),
        ];
    }
}
