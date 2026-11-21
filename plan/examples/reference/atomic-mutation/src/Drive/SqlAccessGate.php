<?php

declare(strict_types=1);

namespace VaulterRef\Drive;

use VaulterRef\Database\Pg;

/**
 * BOUNDARY: the grant subset of Vaulter\Drive\Access\AccessResolver::applicableGrants() — the node's own
 * grants of any scope, ancestors' grants of scope 'subtree', the chain cut after the first node with
 * inherit_grants=false, unexpired grants of the subject, plus the drive owner. Permission drivers,
 * overrides and share links stay with the real resolver.
 *
 * One SQL statement: under READ COMMITTED it sees every grant change committed before it starts. Run after
 * the node lock, it therefore orders against RevokeNodeAccessAction on the same node (which locks that row).
 */
final readonly class SqlAccessGate implements AccessGate
{
    public function allows(Pg $db, DriveContext $context, string $nodeId, Ability $ability): bool
    {
        $roles = implode(',', array_map(static fn (string $role): string => $db->pdo->quote($role), $ability->roles()));

        return (bool) $db->value(<<<SQL
            WITH RECURSIVE chain AS (
                SELECT n.id, n.parent_id, n.inherit_grants, n.drive_id, 0 AS depth
                FROM v_nodes n
                WHERE n.id = :node AND n.tenant_id = :tenant AND n.drive_id = :drive AND n.trashed_at IS NULL
                UNION ALL
                SELECT p.id, p.parent_id, p.inherit_grants, p.drive_id, c.depth + 1
                FROM v_nodes p JOIN chain c ON p.id = c.parent_id
                WHERE c.inherit_grants AND p.drive_id = c.drive_id AND c.depth < 64
            )
            SELECT EXISTS (SELECT 1 FROM chain)
               AND (EXISTS (
                       SELECT 1 FROM chain c JOIN v_node_grants g ON g.node_id = c.id
                       WHERE g.subject_type = :subject_type AND g.subject_id = :subject_id
                         AND (g.expires_at IS NULL OR g.expires_at > LOCALTIMESTAMP)
                         AND (c.depth = 0 OR g.scope = 'subtree')
                         AND g.role_key IN ($roles))
                    OR EXISTS (
                       SELECT 1 FROM v_drives d
                       WHERE d.id = :drive AND d.owner_type = :subject_type AND d.owner_id = :subject_id))
            SQL, [
            'node' => $nodeId,
            'tenant' => $context->tenantId,
            'drive' => $context->driveId,
            'subject_type' => $context->subjectType,
            'subject_id' => $context->subjectId,
        ]);
    }
}
