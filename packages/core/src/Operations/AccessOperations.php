<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Access\AccessOperations as Contract;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Data\Access\RevokeGrantInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\Permission;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

final readonly class AccessOperations implements Contract
{
    public function __construct(private OperationContext $context, private NodeLocator $locator, private OperationGuard $guard, private AccessResolver $resolver, private VaulterConfig $config, private GrantNodeAccessAction $create, private RevokeNodeAccessAction $delete) {}

    /** @return Collection<int, NodeGrant> */
    public function grants(Node|int|string $node): Collection
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function grant(Node|int|string $node, GrantInput $grant): NodeGrant
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);

        return $this->create->execute(context: $this->context, input: new GrantNodeAccessInput(node: $node, grant: $grant));
    }

    public function revoke(Node|int|string $node, NodeGrant|int|string $grant): void
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');
        $key = $grant instanceof NodeGrant ? $grant->getRawOriginal($grant->getKeyName()) : $grant;
        $row = $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
        $this->delete->execute(context: $this->context, input: new RevokeGrantInput(node: $node, grant: $row));
    }

    public function explain(Node|int|string $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation
    {
        $node = $this->located($node);
        $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node);
        $subject ??= $this->context->actor->authorizable();
        if ($subject === null) {
            return new AccessExplanation(allowed: true, ability: $ability, reason: AccessExplanation::REASON_SUPER_ALLOW, allowedBits: Permission::all(), deniedBits: 0, level: GrantLevel::Owner, aces: []);
        }

        return $this->resolver->explain(actor: $subject, ability: $ability, node: $node);
    }

    /** @internal Existing HTTP list pagination.
     * @return CursorPaginator<int, NodeGrant>
     */
    public function paginate(Node|int|string $node, int $perPage = 50): CursorPaginator
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage);
    }

    private function located(Node|int|string $node): Node
    {
        $node = $this->locator->find(context: $this->context, key: $node);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }

        return $node;
    }
}
