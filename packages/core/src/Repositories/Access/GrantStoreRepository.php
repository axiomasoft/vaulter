<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Access;

use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

/**
 * Write-side row work for v_node_grants (§2.6). Idempotent upsert on the grant-
 * unique key (node_id, subject_type, subject_id, scope, effect) so re-granting a
 * subject the same scope/effect updates the level/bits/expiry rather than breeding
 * a duplicate. Opens NO transactions and dispatches NO events — the Action does.
 */
final class GrantStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    public function grant(Node $node, GrantNodeAccessInput $data): NodeGrant
    {
        $model = $this->vaulterConfig->model('grant');

        $match = [
            'node_id' => $node->getKey(),
            'subject_type' => $data->subject->type,
            'subject_id' => $data->subject->id,
            'scope' => $data->scope->value,
            'effect' => $data->effect->value,
        ];

        // Manual find-then-forceFill (P1.4/F-P1.8-02) instead of updateOrCreate:
        // Eloquent's updateOrCreate() routes both the match attributes and the
        // values through fill()/$fillable, and NodeGrant is intentionally fully
        // guarded (ACL row) — every key here is server-computed, never raw
        // request input.
        /** @var NodeGrant|null $grant */
        $grant = $model::query()->where($match)->first();

        if (!$grant instanceof NodeGrant) {
            $grant = new $model;
        }

        $grant->forceFill([
            ...$match,
            'level' => $data->level->value,
            'permissions' => $data->permissions,
            'expires_at' => $data->expiresAt,
            'granted_by' => $data->grantedBy,
        ]);
        $grant->save();

        return $grant;
    }

    /**
     * Revoke every live grant (allow + deny, both scopes) a subject holds on a node.
     * Returns the number of rows removed so the Action can no-op an empty revoke.
     */
    public function revoke(Node $node, Subject $subject): int
    {
        $model = $this->vaulterConfig->model('grant');

        $deleted = $model::query()
            ->where('node_id', $node->getKey())
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subject->id)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
