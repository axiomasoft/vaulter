<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Documents\Writing;

use VaulterRef\Ai\Core\Contracts\CommandOwner;
use VaulterRef\Ai\Core\Data\CommandOutcome;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Support\SecretBox;
use VaulterRef\Database\Pg;
use VaulterRef\Documents\Contracts\AtomicDocumentMutation;
use VaulterRef\Documents\Contracts\LockedDocumentMutation;
use VaulterRef\Documents\Exceptions\DocumentMutationException;
use VaulterRef\Drive\Ability;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\DriveContext;
use VaulterRef\Support\FaultPoint;

/**
 * Target: packages/documents/src/Writing/SingleTextOwner.php in vaulter-ai (owner key documents.single_text@1).
 *
 * Inside AtomicDocumentMutation (T → N → Edit → D already held), in this order:
 *  1. stored outcome for the key or for the proposal → return it (fingerprint must match). No mode, generation
 *     or schema check here: a receipt stays with the owner that issued it, even after snapshot→journal.
 *  2. no outcome: expiry, write mode + generation, document type + schema, target block → durable 'rejected'
 *     without replace() when a predictable condition fails;
 *  3. replace() once, then command row, evidence and outbox on connection(); COMMIT by the port.
 * An exception (not a decision) rolls everything back and leaves the key unresolved ("running").
 *
 * When an earlier worker loses the right to write even a rejection: its decision is taken only here, under
 * the D lock, after (1) found no outcome. A worker whose lease expired and whose successor has committed finds
 * that row at (1) and returns it; a worker without current Edit stops before the callback and writes nothing;
 * a rejection computed outside this callback (transport error, timeout, its own stale snapshot) is never stored.
 */
final readonly class SingleTextOwner implements CommandOwner
{
    public const KEY = 'documents.single_text@1';

    public function __construct(
        private AtomicDocumentMutation $atomic,
        private Pg $owner,
        private AccessGate $access,
        private SecretBox $box,
        private FaultPoint $faults,
        private string $dedupeWindow = '7 days',
        private string $evidenceTtl = '24 hours',
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function commit(DriveContext $context, CommandScope $scope, string $fingerprintHex, ?array $plan): CommandOutcome
    {
        try {
            return $this->atomic->run($context, $scope->resourceId,
                fn (LockedDocumentMutation $locked): CommandOutcome => $this->decideLocked($locked, $context, $scope, $fingerprintHex, $plan));
        } catch (DocumentMutationException $error) {
            if ($error->reason === 'resource_unavailable') {
                return CommandOutcome::unavailable();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed>|null $plan */
    public function decideLocked(LockedDocumentMutation $locked, DriveContext $context, CommandScope $scope, string $fingerprintHex, ?array $plan): CommandOutcome
    {
        $db = $locked->connection();
        $state = $locked->snapshot();
        $existing = $this->find($db, $scope, $plan['proposal_id'] ?? null);
        if ($existing !== null) {
            return $this->fromRow($existing, $scope, $fingerprintHex);
        }
        if ($plan === null) {
            return $this->reject($db, $scope, $fingerprintHex, $state->documentId, null, 'proposal_expired');
        }
        $p = ReplaceExactPlan::fromArray($plan);
        if ($p->writer !== self::KEY) {
            throw new \LogicException('A plan for another writer was routed here.');
        }
        if ((bool) $db->value('SELECT CAST(:at AS timestamptz) <= now()', ['at' => $p->expiresAt])) {
            return $this->reject($db, $scope, $fingerprintHex, $state->documentId, $p->proposalId, 'proposal_expired');
        }
        if ($state->writeMode !== $p->writeMode || $state->generation !== $p->generation) {
            return $this->reject($db, $scope, $fingerprintHex, $state->documentId, $p->proposalId, 'generation_changed');
        }
        if ($state->type !== $p->documentType || $state->schemaVersion !== $p->schemaVersion) {
            return $this->reject($db, $scope, $fingerprintHex, $state->documentId, $p->proposalId, 'schema_changed');
        }
        $applied = TextReplacement::apply($state->content, $p);
        if (is_string($applied)) {
            return $this->reject($db, $scope, $fingerprintHex, $state->documentId, $p->proposalId, $applied);
        }

        $after = $locked->replace($applied['doc'], $state->position);
        $receipt = (string) $db->value('SELECT uuidv7()::text');
        $db->execute(<<<'SQL'
            INSERT INTO v_ai_text_commands (tenant_id, project_scope, effective_subject, resource_id, document_id, command_id,
                proposal_id, request_fingerprint, state, receipt_id, source_generation, source_position, dedupe_until)
            VALUES (CAST(:tenant AS bigint), :project, :subject, CAST(:resource AS uuid), CAST(:document AS uuid), :command,
                CAST(:proposal AS uuid), decode(:fingerprint, 'hex'), 'committed', CAST(:receipt AS uuid), :generation, :position,
                now() + CAST(:window AS interval))
            SQL, $scope->params() + ['document' => $state->documentId, 'proposal' => $p->proposalId, 'fingerprint' => $fingerprintHex,
            'receipt' => $receipt, 'generation' => $after->generation, 'position' => $after->position, 'window' => $this->dedupeWindow]);
        $this->faults->reach('owner_after_receipt', $db);
        $evidence = json_encode(['profile' => 'text_and_marks', 'block_id' => $p->blockId, 'block' => $applied['block'],
            'generation' => $after->generation, 'position' => $after->position], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $db->execute(<<<'SQL'
            INSERT INTO v_ai_text_command_evidence (receipt_id, profile, key_id, encrypted_evidence, expires_at)
            VALUES (CAST(:receipt AS uuid), 'text_and_marks', :key, decode(:sealed, 'hex'), now() + CAST(:ttl AS interval))
            SQL, ['receipt' => $receipt, 'key' => $this->box->keyId, 'ttl' => $this->evidenceTtl,
            'sealed' => bin2hex($this->box->seal($evidence, self::evidenceAad($scope, $receipt)))]);
        $db->execute(<<<'SQL'
            INSERT INTO v_outbox (event_id, tenant_id, scope_kind, scope_id, aggregate_type, aggregate_id, sequence, type, external, payload, created_at)
            VALUES (uuidv7()::text, CAST(:tenant AS bigint), 'drive', CAST(:drive AS text), 'office_command', CAST(:receipt AS text), CAST(:position AS bigint), 'office.text_command_committed', false,
                json_build_object('resource_id', CAST(:resource AS text), 'command_id', CAST(:command AS text), 'receipt_id', CAST(:receipt AS text),
                                  'generation', CAST(:generation AS bigint), 'position', CAST(:position AS bigint)), LOCALTIMESTAMP(0))
            SQL, ['tenant' => $scope->tenantId, 'drive' => $context->driveId, 'receipt' => $receipt, 'position' => $after->position,
            'resource' => $scope->resourceId, 'command' => $scope->commandId, 'generation' => $after->generation]);
        $this->faults->reach('owner_before_commit', $db);

        return CommandOutcome::committed($receipt, $after->generation, $after->position);
    }

    public function status(DriveContext $context, CommandScope $scope, string $fingerprintHex): CommandOutcome
    {
        return $this->owner->transaction(function (Pg $db) use ($context, $scope, $fingerprintHex): CommandOutcome {
            if (!$this->access->allows($db, $context, $scope->resourceId, Ability::View)) {
                return CommandOutcome::unavailable();
            }
            $row = $this->find($db, $scope, null);

            return $row === null ? CommandOutcome::running() : $this->fromRow($row, $scope, $fingerprintHex);
        });
    }

    /** @return array<string, mixed>|null */
    private function find(Pg $db, CommandScope $scope, ?string $proposalId): ?array
    {
        return $db->row(<<<'SQL'
            SELECT command_id, encode(request_fingerprint, 'hex') AS fingerprint, state, reason, receipt_id::text AS receipt_id,
                   source_generation, source_position
            FROM v_ai_text_commands
            WHERE tenant_id = CAST(:tenant AS bigint) AND project_scope = :project AND effective_subject = :subject
              AND resource_id = CAST(:resource AS uuid)
              AND (command_id = :command OR proposal_id = CAST(:proposal AS uuid))
            ORDER BY (command_id = :command) DESC
            LIMIT 1
            SQL, $scope->params() + ['proposal' => $proposalId]);
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row, CommandScope $scope, string $fingerprintHex): CommandOutcome
    {
        if ($row['command_id'] !== $scope->commandId || !hash_equals($row['fingerprint'], $fingerprintHex)) {
            return CommandOutcome::conflict();
        }

        return $row['state'] === 'committed'
            ? CommandOutcome::committed($row['receipt_id'], (int) $row['source_generation'], (int) $row['source_position'])
            : CommandOutcome::rejected($row['reason']);
    }

    private function reject(Pg $db, CommandScope $scope, string $fingerprintHex, string $documentId, ?string $proposalId, string $reason): CommandOutcome
    {
        if ($proposalId === null) {
            return CommandOutcome::rejected($reason); // purged payload: nothing durable can be bound to an unknown proposal
        }
        $db->execute(<<<'SQL'
            INSERT INTO v_ai_text_commands (tenant_id, project_scope, effective_subject, resource_id, document_id, command_id,
                proposal_id, request_fingerprint, state, reason, dedupe_until)
            VALUES (CAST(:tenant AS bigint), :project, :subject, CAST(:resource AS uuid), CAST(:document AS uuid), :command,
                CAST(:proposal AS uuid), decode(:fingerprint, 'hex'), 'rejected', :reason, now() + CAST(:window AS interval))
            SQL, $scope->params() + ['document' => $documentId, 'proposal' => $proposalId, 'fingerprint' => $fingerprintHex,
            'reason' => $reason, 'window' => $this->dedupeWindow]);

        return CommandOutcome::rejected($reason);
    }

    public static function evidenceAad(CommandScope $scope, string $receiptId): string
    {
        return implode("\x1f", ['v_ai_text_command_evidence', $receiptId, $scope->tenantId, $scope->projectScope, $scope->effectiveSubject, $scope->resourceId]);
    }
}
