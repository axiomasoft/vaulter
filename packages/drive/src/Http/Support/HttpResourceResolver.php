<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StorageNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Idempotency\IdempotencyDigest;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Operations\ScopeRef;

/**
 * Turns the scalar route parameters of the versioned API into the drive an operation
 * runs on. Both lookups go through the tenant-scoped repositories, and a drive that is
 * missing, belongs to another tenant or is being deleted answers exactly like one that
 * never existed, so a caller cannot probe ids across tenants. Nested resources (grants,
 * shares, revisions) are checked against their node by the operations themselves.
 */
#[Internal]
final readonly class HttpResourceResolver
{
    public function __construct(
        private DriveReadRepository $storages,
        private NodeReadRepository $nodes,
        private IdempotencyStore $receipts,
    ) {}

    public function drive(string $id): Drive
    {
        $drive = $this->storages->find($id);

        return $drive instanceof Drive && $drive->status !== DriveStatus::Deleting
            ? $drive
            : throw StorageNotFoundException::forId($id);
    }

    /** The drive holding a node, trashed nodes included; an unknown node is node_not_found. */
    public function driveOfNode(string $nodeId): Drive
    {
        $node = $this->nodes->find($nodeId) ?? throw NodeNotFoundException::forId($nodeId);
        $drive = $this->storages->find($node->drive_id);

        return $drive instanceof Drive && $drive->status !== DriveStatus::Deleting
            ? $drive
            : throw NodeNotFoundException::forId($nodeId);
    }

    /**
     * {@see driveOfNode()} for the route of an operation whose first request removes the node (a purge). A keyed retry
     * of a request that committed names a node that is gone; its drive is then the scope of the caller's own live
     * receipt of `$operation` for exactly this request (key and transport fingerprint of {@see ReadIdempotencyKey}),
     * so the operation can answer it from that receipt. Nothing is trusted from the row: the drive is resolved like
     * any other, the claim compares the whole tuple again and the owner authorizes the replay. Without such a
     * receipt the node is node_not_found, as before.
     */
    public function driveOfNodeOrReceipt(string $nodeId, Request $request, string $operation, ActorRef $actor): Drive
    {
        try {
            return $this->driveOfNode($nodeId);
        } catch (NodeNotFoundException $missing) {
            $key = ReadIdempotencyKey::key($request);
            $fingerprint = ReadIdempotencyKey::requestFingerprint($request);
            $scope = $key === null || $fingerprint === null ? null : $this->receipts->scopeOf($operation, $key, $actor, IdempotencyDigest::transport($fingerprint));
            if ($scope?->kind !== ScopeRef::DRIVE) {
                throw $missing;
            }
            try {
                return $this->drive($scope->id);
            } catch (StorageNotFoundException) {
                throw $missing;
            }
        }
    }
}
