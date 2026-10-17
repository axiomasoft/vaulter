<?php

declare(strict_types=1);

namespace Vaulter\Testing\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Facades\Vaulter;
use Vaulter\Testing\Fixtures\FixtureActor;
use Vaulter\Testing\Fixtures\FixtureOwner;

/**
 * The shared fixture of the permission contract: a writer subtree grant for the actor
 * on $parent, and $child below it with inheritance restricted and no grant of its own.
 * Every driver that adds no roles or override must decide the same on it.
 */
#[Api]
final readonly class PermissionFixture
{
    #[Internal]
    public function __construct(
        public Drive $drive,
        public Node $parent,
        public Node $child,
        public Authorizable $actor,
    ) {}

    /** Built through Operations, so the tree and the grant are the ones a host would have. */
    public static function build(?Model $owner = null, ?Authorizable $actor = null): self
    {
        $owner ??= FixtureOwner::next();
        $actor ??= FixtureActor::next();
        $key = $actor instanceof Model ? $actor->getKey() : null;
        if (!$actor instanceof Model || (!is_int($key) && !is_string($key))) {
            throw new InvalidArgumentException('The fixture actor must be an Eloquent model with a scalar key.');
        }

        $drive = Vaulter::drive($owner)->asSystem('permission fixture');
        $root = $drive->root();
        $parent = $drive->folders()->create('parent', $root->id);
        $child = $drive->folders()->create('child', $parent->id);

        $drive->access()->grant($parent->id, new GrantInput(
            subject: new Subject(type: $actor->getMorphClass(), id: $key),
            role: RoleRegistry::WRITER,
            scope: GrantScope::Subtree,
        ));
        $drive->access()->restrictInheritance($child->id, InheritanceRestriction::Empty);

        $nodes = App::make(VaulterConfig::class)->model('node');

        return new self(
            drive: $drive->model(),
            parent: $nodes::query()->findOrFail($parent->id),
            child: $nodes::query()->findOrFail($child->id),
            actor: $actor,
        );
    }
}
