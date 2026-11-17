<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Pagination\CursorPaginator;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Contracts\Activity\ActivityOperations as Contract;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\ActivitySnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Queries\Activity\VisibleActivityQuery;
use Vaulter\Events\EventTypeContract;

#[Api]
final readonly class ActivityOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private VisibleActivityQuery $query, private ActivitySnapshotMapper $mapper) {}

    /** @return Page<ActivityEntrySnapshot> */
    public function forDrive(?ListOptions $options = null): Page
    {
        return $this->page($this->filtered(options: $options));
    }

    /** @return Page<ActivityEntrySnapshot> */
    public function forNode(NodeSnapshot|string|int $node, ?ListOptions $options = null): Page
    {
        return $this->page($this->filtered(node: $node, options: $options));
    }

    /** @internal Existing HTTP verb filter, without widening the handle contract.
     * @return CursorPaginator<int, ActivityEntrySnapshot>
     */
    #[Internal]
    public function filtered(NodeSnapshot|string|int|null $node = null, ?ListOptions $options = null, ?EventTypeContract $verb = null): CursorPaginator
    {
        $drive = $this->context->drive->newQuery()->whereKey($this->context->driveId())->first() ?? throw NodeNotFoundException::forId($this->context->driveId());
        $context = DriveContext::for(drive: $drive, actor: $this->context->actor, correlationId: $this->context->correlationId);
        $target = $node === null ? null : $this->locator->find(context: $context, key: $node);
        $root = $target ?? $this->locator->find(context: $context, key: $drive->root_node_id ?? 'missing-root');
        if ($root->trashed_at !== null) {
            throw NodeNotFoundException::forId($root->id);
        }
        $this->guard->authorize(context: $context, ability: Ability::View, node: $root);

        return $this->query->execute(context: $context, options: $options ?? new ListOptions, node: $target, verb: $verb)->convert(fn (Activity $entry): ActivityEntrySnapshot => $this->mapper->map($entry));
    }

    /**
     * @param  CursorPaginator<int, ActivityEntrySnapshot> $paginator
     * @return Page<ActivityEntrySnapshot>
     */
    private function page(CursorPaginator $paginator): Page
    {
        return new Page(items: array_values($paginator->items()), nextCursor: $paginator->nextCursor()?->encode());
    }
}
