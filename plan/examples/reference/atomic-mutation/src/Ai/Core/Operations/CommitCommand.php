<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Operations;

use VaulterRef\Ai\Core\Contracts\CommandOwner;
use VaulterRef\Ai\Core\Contracts\ResourceDirectory;
use VaulterRef\Ai\Core\Data\CommandScope;
use VaulterRef\Ai\Core\Persistence\OwnerRoutes;
use VaulterRef\Ai\Core\Persistence\Proposals;
use VaulterRef\Ai\Core\Protocol\RequestFingerprint;
use VaulterRef\Ai\Core\Protocol\Wire;
use VaulterRef\Drive\DriveContext;
use VaulterRef\Support\FaultPoint;

/**
 * Target: packages/core/src/Operations/Commit.php in vaulter-ai. Three commits, never one transaction across
 * connections:
 *   #1 CORE  route (key → proposal, fingerprint, owner_key)   — before any domain write
 *   #2 OWNER content + receipt + evidence + outbox            — inside AtomicDocumentMutation
 *   #3 CORE  receipt locator                                  — before the answer leaves the process
 */
final readonly class CommitCommand
{
    /** @param array<string, CommandOwner> $owners by owner key */
    public function __construct(
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private ResourceDirectory $directory,
        private array $owners,
        private FaultPoint $faults,
    ) {}

    /** @return array{http:int, body:array<string, mixed>} */
    public function handle(DriveContext $context, CommandScope $scope, string $proposalId, string $digestHex): array
    {
        $op = 'office.commit';
        $route = $this->routes->find($scope);
        if ($route === null) {
            $proposal = $this->proposals->load($scope, $proposalId);
            if ($proposal === null || $proposal['plan'] === null) {
                return Wire::error($op, 'resource_unavailable');
            }
            if (!hash_equals($proposal['digest'], $digestHex)) {
                return Wire::error($op, 'precondition_failed');
            }
            if ($proposal['decision'] !== 'approved') {
                return Wire::error($op, $proposal['decision'] === 'pending' ? 'approval_required' : 'precondition_failed');
            }
            if ($proposal['expired']) {
                return Wire::error($op, 'proposal_expired');
            }
            $ownerKey = (string) ($proposal['plan']['writer'] ?? '');
            if (!isset($this->owners[$ownerKey])) {
                return Wire::error($op, 'temporarily_unavailable'); // the writer pinned at prepare is not installed here
            }
            $fingerprint = RequestFingerprint::of($proposal['command'], $proposalId, $digestHex);
            $route = $this->routes->bind($scope, $proposalId, $fingerprint, $ownerKey);
            if ($route === null) {
                return $this->hiddenOr($context, $scope, 'idempotency_conflict');
            }
            $this->faults->reach('after_route_bound');
        } else {
            $proposal = $this->proposals->load($scope, $route['proposal_id']);
        }

        // The same key must carry the same plan: compare before anything is sent to the owner.
        $incoming = $proposal === null ? null : RequestFingerprint::of($proposal['command'], $proposalId, $digestHex);
        if ($route['proposal_id'] !== $proposalId || $incoming === null || !hash_equals($route['fingerprint'], $incoming)) {
            return $this->hiddenOr($context, $scope, 'idempotency_conflict');
        }
        if (!$route['live']) {
            return $this->hiddenOr($context, $scope, 'idempotency_window_expired');
        }

        // The retry goes to the owner that took the key, not to the writer of the current mode.
        $owner = $this->owners[$route['owner_key']] ?? null;
        if ($owner === null) {
            return Wire::error($op, 'temporarily_unavailable');
        }
        $outcome = $owner->commit($context, $scope, $route['fingerprint'], $proposal['plan']);
        $this->faults->reach('after_owner_commit');
        if ($outcome->state === 'committed' && $route['receipt_id'] !== $outcome->receiptId) {
            $this->routes->settle($scope, (string) $outcome->receiptId);
        }

        return Wire::outcome($op, $scope->commandId, $outcome);
    }

    /** @return array{http:int, body:array<string, mixed>} */
    private function hiddenOr(DriveContext $context, CommandScope $scope, string $code): array
    {
        return Wire::error('office.commit', $this->directory->visible($context, $scope->resourceId) ? $code : 'resource_unavailable');
    }
}
