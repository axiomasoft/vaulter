<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Access\OperationGuard;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Drive\TrashOperations as Contract;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Actions\Trash\RestoreNodeAction;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Data\Trash\RestoreNodeInput;
use Vaulter\Drive\Queries\ListTrashQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\RestoreDestinationChangedException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Repositories\Drive\NodeReadRepository;

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
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->write('restore', function () use ($node, $options): NodeSnapshot {
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
                });
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
        $source = null;
        try {
            $this->write('purge', function () use ($node, $expectedSequence, &$source): void {
                $source = null;
                $located = $this->locator->find(context: $this->context, key: $node, includeTrashed: true);
                $this->guard->authorize(context: $this->context, ability: Ability::Delete, node: $located, operation: OperationKind::Purge);
                $source = $located;
                $this->purgeAction->execute(context: $this->context, data: new PurgeNodeInput(node: $located, expectedSequence: $expectedSequence));
            });
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
        // Materialize before commits remove rows; every root owns one atomic operation.
        $roots = $this->nodes->trashedBefore(Carbon::now())->where('drive_id', $this->context->driveId())->get();
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
    private function write(string $verb, Closure $attempt): mixed
    {
        return $this->runner->write(new OperationSpec('drive.trash.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt);
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
