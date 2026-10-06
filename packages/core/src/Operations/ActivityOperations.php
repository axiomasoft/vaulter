<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Activity\ActivityOperations as Contract;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Events\EventType;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Queries\Activity\VisibleActivityQuery;

final readonly class ActivityOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private VisibleActivityQuery $query) {}

    /** @return CursorPaginator<int, Activity> */
    public function forDrive(?ListOptions $options = null): CursorPaginator
    {
        return $this->filtered(options: $options);
    }

    /** @return CursorPaginator<int, Activity> */
    public function forNode(Node|int|string $node, ?ListOptions $options = null): CursorPaginator
    {
        return $this->filtered(node: $node, options: $options);
    }

    /** @internal Existing HTTP verb filter, without widening the handle contract.
     * @return CursorPaginator<int, Activity>
     */
    public function filtered(Node|int|string|null $node = null, ?ListOptions $options = null, ?EventType $verb = null): CursorPaginator
    {
        $drive = $this->context->drive->newQuery()->whereKey($this->context->driveId())->first() ?? throw NodeNotFoundException::forId($this->context->driveId());
        $context = new OperationContext(drive: $drive, actor: $this->context->actor, correlationId: $this->context->correlationId);
        $target = $node === null ? null : $this->locator->find(context: $context, key: $node);
        $root = $target ?? $this->locator->find(context: $context, key: $drive->root_node_id ?? 'missing-root');
        if ($root->trashed_at !== null) {
            throw NodeNotFoundException::forId($root->id);
        }
        $this->guard->authorize(context: $context, ability: Ability::View, node: $root);

        return $this->query->execute(context: $context, options: $options ?? new ListOptions, node: $target, verb: $verb);
    }
}
