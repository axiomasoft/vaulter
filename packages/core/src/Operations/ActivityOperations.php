<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Activity\ActivityOperations as Contract;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Data\Snapshots\Mapping\ActivitySnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Events\EventType;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Activity\Activity;
use Vaulter\Queries\Activity\VisibleActivityQuery;

final readonly class ActivityOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private VisibleActivityQuery $query, private ActivitySnapshotMapper $mapper) {}

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
    public function filtered(NodeSnapshot|string|int|null $node = null, ?ListOptions $options = null, ?EventType $verb = null): CursorPaginator
    {
        $drive = $this->context->drive->newQuery()->whereKey($this->context->driveId())->first() ?? throw NodeNotFoundException::forId($this->context->driveId());
        $context = new OperationContext(drive: $drive, actor: $this->context->actor, correlationId: $this->context->correlationId);
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
