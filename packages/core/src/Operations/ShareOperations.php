<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\OperationGuard;
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Actions\Sharing\RotateShareLinkAction;
use Vaulter\Actions\Sharing\UpdateShareLinkAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Sharing\ShareOperations as Contract;
use Vaulter\Data\Sharing\CreateShareLinkInput;
use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\RevokeShareInput;
use Vaulter\Data\Sharing\RotateShareLinkInput;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Data\Sharing\ShareLinkUpdate;
use Vaulter\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;

final readonly class ShareOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private VaulterConfig $config, private CreateShareLinkAction $createLink, private RevokeShareLinkAction $deleteLink, private UpdateShareLinkAction $updateLink, private RotateShareLinkAction $rotateLink) {}

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

    public function update(Node|int|string $node, NodeShare|int|string $share, ShareLinkUpdate $changes): NodeShare
    {
        $node = $this->authorized($node);

        return $this->updateLink->execute(context: $this->context, input: new UpdateShareLinkInput(node: $node, share: $this->owned($node, $share), changes: $changes));
    }

    public function rotate(Node|int|string $node, NodeShare|int|string $share): MintedShareLink
    {
        $node = $this->authorized($node);

        return $this->rotateLink->execute(context: $this->context, input: new RotateShareLinkInput(node: $node, share: $this->owned($node, $share)));
    }

    public function revoke(Node|int|string $node, NodeShare|int|string $share): void
    {
        $node = $this->authorized($node);
        $this->deleteLink->execute(context: $this->context, input: new RevokeShareInput(node: $node, share: $this->owned($node, $share)));
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

    /** The link, provided it hangs on this node; any other id looks like a missing node. */
    private function owned(Node $node, NodeShare|int|string $share): NodeShare
    {
        $class = $this->config->model('node_share');
        $key = $share instanceof NodeShare ? $share->getRawOriginal($share->getKeyName()) : $share;

        return $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
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
