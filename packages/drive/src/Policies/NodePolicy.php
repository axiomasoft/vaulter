<?php

declare(strict_types=1);

namespace Vaulter\Drive\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Models\Node;

/**
 * The Drive authorization policy: a thin delegation to the Vaulter-owned
 * {@see AccessResolver}, which runs the effective-permission algorithm over
 * v_node_grants + the host-role driver. Each method maps a Gate ability to an
 * {@see Ability} the resolver answers; the controller calls
 * $this->authorize('view', $node) and Laravel's Gate dispatches here.
 *
 * Registered against the swappable Node model in the service provider, so a host
 * subclass of Node is governed by the same policy.
 */
#[Internal]
final readonly class NodePolicy
{
    public function __construct(
        private AccessResolver $resolver,
    ) {}

    public function view(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::View, $node);
    }

    public function download(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Download, $node);
    }

    public function comment(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Comment, $node);
    }

    public function edit(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Edit, $node);
    }

    public function create(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Create, $node);
    }

    public function move(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Move, $node);
    }

    public function copy(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Copy, $node);
    }

    public function trash(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Trash, $node);
    }

    public function restore(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Restore, $node);
    }

    public function share(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Share, $node);
    }

    public function manage(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Manage, $node);
    }

    public function delete(Authorizable $actor, Node $node): bool
    {
        return $this->resolver->can($actor, Ability::Delete, $node);
    }
}
