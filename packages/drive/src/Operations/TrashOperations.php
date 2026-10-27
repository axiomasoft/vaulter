<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Contracts\TrashOperations as Contract;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\RestoreDestinationChangedException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Idempotency\NodeReplayAuthorizer;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Drive\Idempotency\PurgeReceipt;
use Vaulter\Drive\Idempotency\PurgeReceiptCodec;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Queries\ListTrashQuery;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class TrashOperations implements Contract
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private NodeReadRepository $nodes,
        private ListTrashQuery $listing,
        private RestoreNodeAction $restoreAction,
        private PurgeNodeAction $purgeAction,
        private NodeSnapshotMapper $mapper,
        private OperationRunner $runner,
    ) {}

    /** @return Page<NodeSnapshot> */
    public function list(?ListOptions $options = null): Page
    {
        return $this->mapper->page($this->listing->execute(context: $this->context, options: $options ?? new ListOptions), $this->context->actor->ref());
    }

    public function restore(NodeSnapshot|string|int $node, ?RestoreOptions $options = null): NodeSnapshot
    {
        $options ??= new RestoreOptions;
        $nodeId = self::idOf($node);
        // Idempotent by key (D25): the receipt is the restored node's snapshot; a replay needs Restore on it where it is now.
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Restore, $nodeId, includeTrashed: true),
            fingerprint: ['node' => $nodeId, 'destination' => $options->destination === null ? null : self::idOf($options->destination), 'on_conflict' => $options->onConflict->value, 'expected_sequence' => $options->expectedSequence],
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $restored = $this->runner->write(new OperationSpec('drive.trash.restore', new ModuleId('drive')), $this->context->operation, function () use ($node, $options): NodeSnapshot {
                    $source = $this->locator->find(context: $this->context, key: $node, includeTrashed: true);
                    $this->guard->authorize(context: $this->context, ability: Ability::Restore, node: $source, operation: OperationKind::Mutation);
                    $destination = $this->restoreAction->resolveDestination(context: $this->context, node: $source, explicit: $options->destination);
                    $this->guard->authorize(context: $this->context, ability: Ability::View, node: $destination);
                    $originalId = $source->trashed_from_parent_id;
                    if ($originalId === null || (string) $destination->id !== (string) $originalId) {
                        $this->guard->authorizeCreate(context: $this->context, parent: $destination);
                    }

                    return $this->mapper->map($this->restoreAction->execute(context: $this->context,
                        data: new RestoreNodeInput(node: $source, destination: $destination, onConflict: $options->onConflict, expectedSequence: $options->expectedSequence)), $this->context->actor->ref());
                }, $idempotency);

                // A replayed receipt is semantic and keyless: the authorized replay gets the reader key afresh.
                return $restored->syncKey === null ? $this->mapper->withSyncKey($restored, $this->context->actor->ref()) : $restored;
            } catch (RestoreDestinationChangedException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new RestoreDestinationChangedException;
    }

    public function purge(NodeSnapshot|string|int $node, ?int $expectedSequence = null): void
    {
        $nodeId = self::idOf($node);
        // Idempotent by key (D25): the receipt keeps the id and the scope the right to purge was proven in (ID2); a
        // replay checks Delete in that scope, since the node itself is gone.
        $idempotency = new IdempotentWrite(
            codec: new PurgeReceiptCodec,
            authorizer: NodeReplayAuthorizer::forNode($this->context, $this->locator, $this->guard, Ability::Delete, $nodeId, OperationKind::Purge, includeTrashed: true),
            fingerprint: ['node' => $nodeId, 'expected_sequence' => $expectedSequence],
        );
        $source = null;
        try {
            $this->write('purge', function () use ($node, $expectedSequence, &$source): PurgeReceipt {
                $source = null;
                $located = $this->locator->find(context: $this->context, key: $node, includeTrashed: true);
                $this->guard->authorize(context: $this->context, ability: Ability::Delete, node: $located, operation: OperationKind::Purge);
                $source = $located;
                $receipt = new PurgeReceipt(kind: PurgeReceipt::NODE, id: (string) $located->id, driveId: (string) $this->context->driveId(), scopeNodeId: $located->parent_id === null ? null : (string) $located->parent_id, ability: Ability::Delete->value);
                $this->purgeAction->execute(context: $this->context, data: new PurgeNodeInput(node: $located, expectedSequence: $expectedSequence));

                return $receipt;
            }, $idempotency);
        } catch (Throwable $exception) {
            // Only a failure of the purge itself is logged: a node that is not found or
            // not allowed answers the caller without an error log.
            if ($source instanceof Node) {
                $this->reportFailure($source, $exception);
            }
            throw $exception;
        }
    }

    public function empty(): int
    {
        // Idempotent by key (D25): the keyed call is one operation of its own that holds every root purge, so its
        // receipt is the count and a retry never purges what a later upload put into the trash. A replay returns the
        // count only to an actor who may still purge on the drive's root. Without a key every root stays its own
        // atomic operation, as before.
        if ($this->context->idempotencyKey !== null) {
            $idempotency = new IdempotentWrite(
                codec: new PurgeReceiptCodec,
                authorizer: NodeReplayAuthorizer::forDrive($this->context, $this->locator, $this->guard),
                fingerprint: ['drive' => (string) $this->context->driveId()],
            );

            return $this->runner->write(new OperationSpec('drive.trash.empty', new ModuleId('drive')), $this->context->operation, function (): PurgeReceipt {
                $count = $this->purgeRoots();

                return new PurgeReceipt(kind: PurgeReceipt::TRASH, id: (string) $this->context->driveId(), driveId: (string) $this->context->driveId(), scopeNodeId: null, ability: Ability::Delete->value, count: $count);
            }, $idempotency)->count;
        }

        return $this->purgeRoots();
    }

    private function purgeRoots(): int
    {
        // Materialize before commits remove rows; every root owns one atomic operation.
        // Newest root first: a folder trashed after its already-trashed child comes before that child, so purging it takes the
        // child along and the child is skipped as gone, whatever the clock resolution (microseconds on PostgreSQL, seconds on MySQL).
        $roots = $this->nodes->trashedBefore(Carbon::now())->where('drive_id', $this->context->driveId())
            ->reorder()->orderByDesc('trashed_at')->orderBy('id')->get();
        $count = 0;
        $firstFailure = null;
        foreach ($roots as $root) {
            $authorized = false;
            try {
                $this->write('purge', function () use ($root, &$authorized): void {
                    $authorized = false;
                    $source = $this->locator->find(context: $this->context, key: $root, includeTrashed: true);
                    $this->guard->authorize(context: $this->context, ability: Ability::Delete, node: $source, operation: OperationKind::Purge);
                    $authorized = true;
                    $this->purgeAction->execute(context: $this->context, data: new PurgeNodeInput(node: $source));
                });
                $count++;
            } catch (PermissionDeniedException|NodeNotFoundException|ProfileRestrictionException $refused) {
                if ($authorized) {
                    $this->reportFailure($root, $refused);
                    $firstFailure ??= $refused;
                }
            } catch (Throwable $exception) {
                $this->reportFailure($root, $exception);
                $firstFailure ??= $exception;
            }
        }
        if ($firstFailure !== null) {
            throw $firstFailure;
        }

        return $count;
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, ?IdempotentWrite $idempotency = null): mixed
    {
        return $this->runner->write(new OperationSpec('drive.trash.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private function reportFailure(Node $root, Throwable $exception): void
    {
        Log::error('Vaulter trash root purge failed.', [
            'root_id' => $root->id,
            'correlation_id' => $this->context->correlationId,
            'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
        ]);
    }
}
