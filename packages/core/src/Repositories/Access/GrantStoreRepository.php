<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Access;

use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

/**
 * Write-side row work for v_node_grants (§2.6). Idempotent upsert on the grant-
 * unique key (node_id, subject_type, subject_id, scope) so re-granting a subject
 * the same scope replaces its role/expiry rather than breeding a duplicate. Opens
 * NO transactions and dispatches NO events — the Action does.
 */
final class GrantStoreRepository
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
    ) {}

    public function grant(Node $node, GrantInput $data, int|string|null $grantedBy = null): NodeGrant
    {
        return $this->put(node: $node, subject: $data->subject, scope: $data->scope, role: (new RoleRegistry)->latest($data->role), expiresAt: $data->expiresAt, grantedBy: $grantedBy);
    }

    public function put(Node $node, Subject $subject, GrantScope $scope, Role $role, ?Carbon $expiresAt, int|string|null $grantedBy): NodeGrant
    {
        $model = $this->vaulterConfig->model('grant');

        $match = [
            'node_id' => $node->getKey(),
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'scope' => $scope->value,
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
            'tenant_id' => $node->tenant_id,
            'role_key' => $role->key,
            'role_version' => $role->version,
            'expires_at' => $expiresAt,
            'granted_by' => $grantedBy,
        ]);
        $grant->save();

        return $grant;
    }

    /**
     * Revoke every grant (both scopes) a subject holds on a node.
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
