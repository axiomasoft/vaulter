<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Data\Snapshots\MintedShareLink;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Drive\Actions\Sharing\RotateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\UpdateShareLinkAction;
use Vaulter\Drive\Contracts\Sharing\ShareOperations as Contract;
use Vaulter\Drive\Data\Sharing\CreateShareLinkInput;
use Vaulter\Drive\Data\Sharing\RevokeShareInput;
use Vaulter\Drive\Data\Sharing\RotateShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class ShareOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private VaulterConfig $config, private CreateShareLinkAction $createLink, private RevokeShareLinkAction $deleteLink, private UpdateShareLinkAction $updateLink, private RotateShareLinkAction $rotateLink, private AccessSnapshotMapper $mapper, private OperationRunner $runner) {}

    /** @return list<ShareLinkSnapshot> */
    public function list(NodeSnapshot|string|int $node): array
    {
        $node = $this->authorized($node, mutation: false);
        $class = $this->config->model('node_share');

        return array_values($class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (NodeShare $share): ShareLinkSnapshot => $this->mapper->share($share))->all());
    }

    public function create(NodeSnapshot|string|int $node, ShareLinkInput $input): MintedShareLink
    {
        return $this->write('create', function () use ($node, $input): MintedShareLink {
            $node = $this->authorized($node);
            $minted = $this->createLink->execute(context: $this->context, input: new CreateShareLinkInput(node: $node, link: $input));

            return new MintedShareLink(link: $this->mapper->share($minted->share), token: $minted->token);
        });
    }

    public function update(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ShareLinkUpdate $changes): ShareLinkSnapshot
    {
        return $this->write('update', function () use ($node, $share, $changes): ShareLinkSnapshot {
            $node = $this->authorized($node);

            return $this->mapper->share($this->updateLink->execute(context: $this->context, input: new UpdateShareLinkInput(node: $node, share: $this->owned($node, $share), changes: $changes)));
        });
    }

    public function rotate(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share): MintedShareLink
    {
        return $this->write('rotate', function () use ($node, $share): MintedShareLink {
            $node = $this->authorized($node);
            $minted = $this->rotateLink->execute(context: $this->context, input: new RotateShareLinkInput(node: $node, share: $this->owned($node, $share)));

            return new MintedShareLink(link: $this->mapper->share($minted->share), token: $minted->token);
        });
    }

    public function revoke(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share): void
    {
        $this->write('revoke', function () use ($node, $share): void {
            $node = $this->authorized($node);
            $this->deleteLink->execute(context: $this->context, input: new RevokeShareInput(node: $node, share: $this->owned($node, $share)));
        });
    }

    /** @internal Existing HTTP list pagination.
     * @return CursorPaginator<int, ShareLinkSnapshot>
     */
    #[Internal]
    public function paginate(NodeSnapshot|string|int $node, int $perPage = 50): CursorPaginator
    {
        $node = $this->authorized($node, mutation: false);
        $class = $this->config->model('node_share');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage)->through(fn (NodeShare $share): ShareLinkSnapshot => $this->mapper->share($share));
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt): mixed
    {
        return $this->runner->write(new OperationSpec('drive.shares.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt);
    }

    /** The link, provided it hangs on this node; any other id looks like a missing node. */
    private function owned(Node $node, ShareLinkSnapshot|string|int $share): NodeShare
    {
        $class = $this->config->model('node_share');
        $key = $share instanceof ShareLinkSnapshot ? $share->id : $share;

        return $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
    }

    private function authorized(NodeSnapshot|string|int $node, bool $mutation = true): Node
    {
        $node = $this->locator->find(context: $this->context, key: $node);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->guard->authorize(context: $this->context, ability: Ability::Share, node: $node);
        if ($mutation) {
            $this->guard->assertMutable($this->context, $node);
        }

        return $node;
    }
}
