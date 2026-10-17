<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Sharing\ShareNotFoundException;
use Vaulter\Drive\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\FileRevisionReadRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Drive\Tenancy\ShareTenantContext;
use Vaulter\Support\Identity;

/**
 * Node lookups of the public share routes, which run on the link's own role and
 * node-set instead of an identity. A visitor never sends a real node id: nodes are
 * addressed by the opaque id the share responses show, and only within the one level
 * the link exposes (its node, and the node's direct children for a subtree link), so
 * finding one compares opaque ids over that level.
 * Anything else is the same opaque share_not_found as a bad token.
 */
#[Internal]
final readonly class SharedNodeResolver
{
    public function __construct(
        private NodeReadRepository $nodes,
        private FileRevisionReadRepository $revisions,
        private ShareTenantContext $tenant,
    ) {}

    /** The shared node itself, or one of its listed children by opaque id. */
    public function node(ShareContext $context, ?string $publicId): Node
    {
        if ($publicId === null || $publicId === SharedNodeResource::opaqueId(Identity::key($context->node->getKey()))) {
            return $context->node;
        }
        if ($context->scope() !== GrantScope::Subtree) {
            throw ShareNotFoundException::opaque();
        }
        $drive = $this->drive($context);

        return $this->tenant->evaluate($drive, function () use ($context, $drive, $publicId): Node {
            foreach ($this->nodes->children($drive, $context->node)->cursor() as $child) {
                if ($publicId === SharedNodeResource::opaqueId(Identity::key($child->getKey())) && $context->containsNode($child)) {
                    return $child;
                }
            }

            throw ShareNotFoundException::opaque();
        });
    }

    /**
     * The direct children of the shared folder that are inside the link, name order.
     *
     * @return CursorPaginator<int, Node>
     */
    public function children(ShareContext $context, ?string $cursor, int $perPage): CursorPaginator
    {
        $drive = $this->drive($context);
        /** @var CursorPaginator<int, Node> $page */
        $page = $this->tenant->evaluate($drive, fn (): CursorPaginator => $this->nodes->children($drive, $context->node)
            ->cursorPaginate(perPage: $perPage, cursor: $cursor));
        // Every row is a direct child of an in-scope folder; checking again keeps a
        // misshaped tree from ever listing a node outside the link.
        $page->setCollection((new Collection($page->items()))->filter(static fn (Node $child): bool => $context->containsNode($child))->values());

        return $page;
    }

    /** The file's current revision, as the link sees it. */
    public function head(ShareContext $context, Node $node): FileRevision
    {
        return $this->tenant->evaluate($this->drive($context), fn (): ?FileRevision => $this->revisions->head($node))
            ?? throw ShareNotFoundException::opaque();
    }

    private function drive(ShareContext $context): Drive
    {
        $drive = $context->node->storage()->first();

        return $drive instanceof Drive ? $drive : throw ShareNotFoundException::opaque();
    }
}
