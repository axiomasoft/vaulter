<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Sharing\ShareOperations as Contract;
use Vaulter\Data\Sharing\CreateShareLinkInput;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\RevokeShareInput;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

final readonly class ShareOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private VaulterConfig $config, private CreateShareLinkAction $createLink, private RevokeShareLinkAction $deleteLink) {}

    /** @return Collection<int, NodeShare> */
    public function list(Node|int|string $node): Collection
    {
        $node = $this->authorized($node);
        $class = $this->config->model('node_share');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function create(Node|int|string $node, ShareLinkInput $input): MintedShareLink
    {
        $node = $this->authorized($node);

        return $this->createLink->execute(context: $this->context, input: new CreateShareLinkInput(node: $node, link: $input));
    }

    public function revoke(Node|int|string $node, NodeShare|int|string $share): void
    {
        $node = $this->authorized($node);
        $class = $this->config->model('node_share');
        $key = $share instanceof NodeShare ? $share->getRawOriginal($share->getKeyName()) : $share;
        $row = $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
        $this->deleteLink->execute(context: $this->context, input: new RevokeShareInput(node: $node, share: $row));
    }

    /** @internal Existing HTTP list pagination.
     * @return CursorPaginator<int, NodeShare>
     */
    public function paginate(Node|int|string $node, int $perPage = 50): CursorPaginator
    {
        $node = $this->authorized($node);
        $class = $this->config->model('node_share');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage);
    }

    private function authorized(Node|int|string $node): Node
    {
        $node = $this->locator->find(context: $this->context, key: $node);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->guard->authorize(context: $this->context, ability: Ability::Share, node: $node);

        return $node;
    }
}
