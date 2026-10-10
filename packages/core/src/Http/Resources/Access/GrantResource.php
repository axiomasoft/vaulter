<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Access;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;

/**
 * A role granted on a node: who holds which role, how far down it reaches and until
 * when. Roles replace the former permission bits.
 *
 * @mixin GrantSnapshot
 */
final class GrantResource extends JsonResource
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
            'subject' => ['type' => $grant->subject->type, 'id' => (string) $grant->subject->id],
            'role' => $grant->role,
            'role_version' => $grant->roleVersion,
            'scope' => $grant->scope->value,
            'expires_at' => SnapshotValue::iso($grant->expiresAt),
            'created_by' => $grant->grantedBy,
            'created_at' => SnapshotValue::iso($grant->createdAt),
        ];
    }
}
