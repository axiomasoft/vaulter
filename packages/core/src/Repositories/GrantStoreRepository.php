<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Models\Node;
use Vaulter\Models\NodeGrant;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_node_grants (§2.6). Idempotent upsert on the grant-
 * unique key (node_id, subject_type, subject_id, scope, effect) so re-granting a
 * subject the same scope/effect updates the level/bits/expiry rather than breeding
 * a duplicate. Opens NO transactions and dispatches NO events — the Action does.
 */
final class GrantStoreRepository
{
    public function grant(Node $node, GrantNodeAccessData $data): NodeGrant
    {
        $model = Config::grantModel();

        /** @var NodeGrant $grant */
        $grant = $model::query()->updateOrCreate(
            attributes: [
                'node_id' => $node->getKey(),
                'subject_type' => $data->subject->type,
                'subject_id' => $data->subject->id,
                'scope' => $data->scope->value,
                'effect' => $data->effect->value,
            ],
            values: [
                'level' => $data->level->value,
                'permissions' => $data->permissions,
                'expires_at' => $data->expiresAt,
                'granted_by' => $data->grantedBy,
            ],
        );

        return $grant;
    }

    /**
     * Revoke every live grant (allow + deny, both scopes) a subject holds on a node.
     * Returns the number of rows removed so the Action can no-op an empty revoke.
     */
    public function revoke(Node $node, Subject $subject): int
    {
        $model = Config::grantModel();

        $deleted = $model::query()
            ->where('node_id', $node->getKey())
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subject->id)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
