<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Persistence;

use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Support\SecretBox;
use VaulterRef\Database\Pg;

/**
 * Target: packages/core/src/Persistence/Proposals.php in vaulter-ai, CORE connection.
 * The private preconditions of a proposal (block, expected fragment, its context hash, marks with attributes,
 * generation, write mode, schema, chosen writer) are pinned by proposal_id in v_ai_proposal_payloads before the
 * client receives proposal_id/digest. The client can only refer to them.
 */
final readonly class Proposals
{
    public function __construct(private Pg $core, private SecretBox $box) {}

    /**
     * @param array<string, mixed> $plan private plan for the owner; $plan['writer'] is the owner key
     */
    public function store(CommandScope $scope, string $proposalId, string $command, string $readToken, int $baseGeneration,
        int $basePosition, string $digestHex, array $plan, string $ttl = '1 hour'): void
    {
        $this->core->transaction(function (Pg $db) use ($scope, $proposalId, $command, $readToken, $baseGeneration, $basePosition, $digestHex, $plan, $ttl): void {
            $db->execute(<<<'SQL'
                INSERT INTO v_ai_proposals (proposal_id, tenant_id, project_scope, effective_subject, resource_id, command_name,
                    read_token_hash, base_generation, base_position, policy_revision, schema_revision, digest,
                    approval_mode, decision, expires_at)
                VALUES (CAST(:id AS uuid), :tenant, :project, :subject, :resource, :command_name, sha256(convert_to(:token, 'UTF8')),
                    :generation, :position, 'policy-1', 'office-v1', decode(:digest, 'hex'), 'automatic', 'approved',
                    now() + CAST(:ttl AS interval))
                SQL, ['id' => $proposalId, 'tenant' => $scope->tenantId, 'project' => $scope->projectScope,
                'subject' => $scope->effectiveSubject, 'resource' => $scope->resourceId, 'command_name' => $command,
                'token' => $readToken, 'generation' => $baseGeneration, 'position' => $basePosition,
                'digest' => $digestHex, 'ttl' => $ttl]);
            $sealed = $this->box->seal(json_encode($plan, JSON_THROW_ON_ERROR), self::aad($scope, $proposalId));
            $db->execute('INSERT INTO v_ai_proposal_payloads (proposal_id, key_id, encrypted_plan) VALUES (CAST(:id AS uuid), :key, decode(:plan, \'hex\'))',
                ['id' => $proposalId, 'key' => $this->box->keyId, 'plan' => bin2hex($sealed)]);
        });
    }

    /**
     * Scoped lookup: a proposal of another tenant, project or subject does not exist for this caller.
     *
     * @return array{command:string, digest:string, decision:string, expired:bool, plan:array<string, mixed>|null}|null
     */
    public function load(CommandScope $scope, string $proposalId): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $proposalId) !== 1) {
            return null;
        }
        $row = $this->core->row(<<<'SQL'
            SELECT p.command_name, encode(p.digest, 'hex') AS digest, p.decision, p.expires_at <= now() AS expired,
                   encode(pp.encrypted_plan, 'hex') AS plan
            FROM v_ai_proposals p LEFT JOIN v_ai_proposal_payloads pp ON pp.proposal_id = p.proposal_id
            WHERE p.proposal_id = CAST(:id AS uuid) AND p.tenant_id = :tenant AND p.project_scope = :project
              AND p.effective_subject = :subject AND p.resource_id = :resource
            SQL, ['id' => $proposalId, 'tenant' => $scope->tenantId, 'project' => $scope->projectScope,
            'subject' => $scope->effectiveSubject, 'resource' => $scope->resourceId]);
        if ($row === null) {
            return null;
        }
        $plan = $row['plan'] === null ? null
            : json_decode($this->box->open((string) hex2bin($row['plan']), self::aad($scope, $proposalId)), true, 64, JSON_THROW_ON_ERROR);

        return ['command' => $row['command_name'], 'digest' => $row['digest'], 'decision' => $row['decision'],
            'expired' => (bool) $row['expired'], 'plan' => $plan];
    }

    private static function aad(CommandScope $scope, string $proposalId): string
    {
        return implode("\x1f", ['v_ai_proposal_payloads', $proposalId, $scope->tenantId, $scope->projectScope, $scope->effectiveSubject, $scope->resourceId]);
    }
}
