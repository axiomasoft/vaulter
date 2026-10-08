<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vaulter\Access\OperationGuard;
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
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\NodeReadRepository;

final readonly class TrashOperations implements Contract
{
    public function __construct(
        private OperationContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private NodeReadRepository $nodes,
        private ListTrashQuery $listing,
        private RestoreNodeAction $restoreAction,
        private PurgeNodeAction $purgeAction,
        private NodeSnapshotMapper $mapper,
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
            $source = $this->locator->find(context: $this->context, key: $node, includeTrashed: true);
            $this->guard->authorize(context: $this->context, ability: Ability::Restore, node: $source, operation: OperationKind::Mutation);
            $destination = $this->restoreAction->resolveDestination(context: $this->context, node: $source, explicit: $options->destination);
            $this->guard->authorize(context: $this->context, ability: Ability::View, node: $destination);
            $originalId = $source->trashed_from_parent_id ?? $source->parent_id;
            if ((string) $destination->id !== (string) $originalId) {
                $this->guard->authorizeCreate(context: $this->context, parent: $destination);
            }
            try {
                return $this->mapper->map($this->restoreAction->execute(context: $this->context,
                    data: new RestoreNodeInput(node: $source, destination: $destination, onConflict: $options->onConflict)), $this->context->actor->ref());
            } catch (RestoreDestinationChangedException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new RestoreDestinationChangedException;
    }

    public function purge(NodeSnapshot|string|int $node): void
    {
        $source = $this->locator->find(context: $this->context, key: $node, includeTrashed: true);
        $this->guard->authorize(context: $this->context, ability: Ability::Delete, node: $source, operation: OperationKind::Purge);
        try {
            $this->purgeAction->execute(context: $this->context, data: new PurgeNodeInput(node: $source));
        } catch (Throwable $exception) {
            $this->reportFailure($source, $exception);
            throw $exception;
        }
    }

    public function empty(): int
    {
        // Materialize before commits remove rows; every root owns one atomic action.
        $roots = $this->nodes->trashedBefore(Carbon::now())->where('drive_id', $this->context->driveId())->get();
        $count = 0;
        $firstFailure = null;
        foreach ($roots as $root) {
            try {
                $source = $this->locator->find(context: $this->context, key: $root, includeTrashed: true);
                $this->guard->authorize(context: $this->context, ability: Ability::Delete, node: $source, operation: OperationKind::Purge);
            } catch (PermissionDeniedException|NodeNotFoundException|ProfileRestrictionException) {
                continue;
            } catch (Throwable $exception) {
                $this->reportFailure($root, $exception);
                $firstFailure ??= $exception;

                continue;
            }
            try {
                $this->purgeAction->execute(context: $this->context, data: new PurgeNodeInput(node: $source));
                $count++;
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

    private function reportFailure(Node $root, Throwable $exception): void
    {
        Log::error('Vaulter trash root purge failed.', [
            'root_id' => $root->id,
            'correlation_id' => $this->context->correlationId,
            'code' => $exception instanceof ProblemException ? $exception->problemCode() : 'unexpected_failure',
        ]);
    }
}
