<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\NodeGrant;

/**
 * The read shape of a node grant (§2.6): the subject, level, effect, scope and
 * expiry the Drive permissions UI lists. Read-only — transforms an already-loaded
 * NodeGrant, never queries.
 *
 * @mixin NodeGrant
 */
final class NodeGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeGrant $grant */
        $grant = $this->resource;

        return [
            'id' => $grant->getKey(),
            'node_id' => $grant->node_id,
            'subject_type' => $grant->subject_type,
            'subject_id' => $grant->subject_id,
            'level' => $grant->level->value,
            'permissions' => $grant->permissions,
            'effect' => $grant->effect->value,
            'scope' => $grant->scope->value,
            'expires_at' => $grant->expires_at,
            'created_at' => $grant->created_at,
        ];
    }
}
