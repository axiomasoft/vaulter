<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Sharing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/**
 * The PUBLIC read shape of a node reached through a bearer share (§2.7 / M2c). It
 * is deliberately leaner than the owner-side NodeResource: it carries NO identity
 * "abilities" map (the share grants exactly its own bits — there is no per-actor
 * ability resolution here) and exposes only the metadata safe for an anonymous
 * holder of the link. It never queries — it transforms an already-loaded node and
 * an optional already-loaded children collection.
 *
 * `id`/`parent_id` are an OPAQUE deterministic projection of the real key (P1.2 /
 * F-P1.3-05, D15, S3.3), never the raw `getKey()` — under `id_type=bigint` the raw
 * key is a sequential integer that would leak creation order/count to an anonymous
 * link holder. The projection is a truncated `hash_hmac('sha256', …, APP_KEY)`:
 * deterministic (the same node always opaques to the same string, so a child's
 * `parent_id` matches its parent's own `id` and a listing keeps a stable key) and
 * non-recoverable (APP_KEY never leaves the server). Safe precisely because no
 * public endpoint ({@see PublicShareController})
 * ever accepts a node id FROM the client — every node it resolves comes from the
 * share-context or an already-loaded relation, never a client-supplied id.
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
            'id' => self::opaqueId(Identity::key($node->getKey())),
            'kind' => $node->kind->value,
            'name' => $node->name,
            'mime' => $node->mime_type,
            'extension' => $node->extension,
            'size_bytes' => $node->size_bytes,
            'parent_id' => self::opaqueId($node->parent_id),
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
            'children' => $this->children === null
                ? null
                : self::collection($this->children),
        ];
    }

    /**
     * The opaque, deterministic, non-recoverable projection of a raw node key
     * (P1.2 / F-P1.3-05) — null in, null out (a root node's `parent_id`). Keyed by
     * APP_KEY (never persisted, never leaves the server), matching how Laravel's
     * own signed-URL machinery derives request-scoped secrets from the same key.
     * Truncated to 32 hex chars (128 bits) — plenty to be collision-free for a
     * per-app id-space, short enough to stay a friendly list key.
     */
    private static function opaqueId(int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $appKey = config('app.key');
        $key = is_string($appKey) ? $appKey : '';

        return substr(hash_hmac('sha256', (string) $id, $key), 0, 32);
    }
}
