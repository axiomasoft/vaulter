<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Persistence;

use LogicException;
use PDOException;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Database\Pg;

/**
 * Target: packages/core/src/Persistence/OwnerRoutes.php in vaulter-ai, CORE connection (may be another database).
 * The route is committed BEFORE the owner is called; the receipt locator is written AFTER the owner committed and
 * before the client gets an answer. A process that dies in between leaves receipt_id NULL; the next commit or
 * status with the same key asks the owner named by owner_key and fills it.
 */
final readonly class OwnerRoutes
{
    public function __construct(private Pg $core) {}

    /** @return array{proposal_id:string, fingerprint:string, owner_key:string, receipt_id:?string, live:bool}|null */
    public function find(CommandScope $scope): ?array
    {
        $row = $this->core->row(<<<'SQL'
            SELECT proposal_id::text AS proposal_id, encode(request_fingerprint, 'hex') AS fingerprint, owner_key, receipt_id,
                   dedupe_until > now() AS live
            FROM v_ai_owner_routes
            WHERE tenant_id = :tenant AND project_scope = :project AND effective_subject = :subject
              AND resource_id = :resource AND command_id = :command
            SQL, $scope->params());
        if ($row !== null) {
            $row['live'] = (bool) $row['live'];
        }

        return $row;
    }

    /**
     * CORE commit #1. Returns the route that now holds the key (possibly an earlier one), or null when the
     * proposal is already bound to another command_id (UNIQUE proposal_id: two clicks, one effect).
     *
     * @return array{proposal_id:string, fingerprint:string, owner_key:string, receipt_id:?string, live:bool}|null
     */
    public function bind(CommandScope $scope, string $proposalId, string $fingerprintHex, string $ownerKey, string $window = '7 days'): ?array
    {
        try {
            $this->core->transaction(fn (Pg $db): int => $db->execute(<<<'SQL'
                INSERT INTO v_ai_owner_routes (tenant_id, project_scope, effective_subject, resource_id, command_id, proposal_id,
                    request_fingerprint, owner_key, dedupe_until)
                VALUES (:tenant, :project, :subject, :resource, :command, CAST(:proposal AS uuid), decode(:fingerprint, 'hex'),
                    :owner_key, now() + CAST(:window AS interval))
                ON CONFLICT (tenant_id, project_scope, effective_subject, resource_id, command_id) DO NOTHING
                SQL, $scope->params() + ['proposal' => $proposalId, 'fingerprint' => $fingerprintHex, 'owner_key' => $ownerKey, 'window' => $window]));
        } catch (PDOException $error) {
            if (Pg::sqlState($error) === '23505') {
                return null; // proposal_id already routed under another command_id
            }
            throw $error;
        }

        return $this->find($scope);
    }

    /** CORE commit #3: the locator of a receipt the owner has already committed. */
    public function settle(CommandScope $scope, string $receiptId): void
    {
        $updated = $this->core->transaction(fn (Pg $db): int => $db->execute(<<<'SQL'
            UPDATE v_ai_owner_routes SET receipt_id = :receipt
            WHERE tenant_id = :tenant AND project_scope = :project AND effective_subject = :subject
              AND resource_id = :resource AND command_id = :command AND (receipt_id IS NULL OR receipt_id = :receipt)
            SQL, $scope->params() + ['receipt' => $receiptId]));
        if ($updated !== 1) {
            throw new LogicException('The owner reported a receipt that differs from the routed one.');
        }
    }
}
