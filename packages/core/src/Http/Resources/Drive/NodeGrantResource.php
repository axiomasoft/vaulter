<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;

/**
 * The read shape of a node grant (§2.6): the subject, level, effect, scope and
 * expiry the Drive permissions UI lists. Read-only — transforms a GrantSnapshot,
 * never queries.
 *
 * @mixin GrantSnapshot
 */
final class NodeGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var GrantSnapshot $grant */
        $grant = $this->resource;

        return [
            'id' => $grant->id,
            'node_id' => $grant->nodeId,
            'subject_type' => $grant->subject->type,
            'subject_id' => $grant->subject->id,
            'role' => $grant->role,
            'role_version' => $grant->roleVersion,
            'scope' => $grant->scope->value,
            'expires_at' => SnapshotValue::iso($grant->expiresAt),
            'created_at' => SnapshotValue::iso($grant->createdAt),
        ];
    }
}
