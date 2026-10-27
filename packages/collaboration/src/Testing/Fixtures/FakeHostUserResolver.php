<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Testing\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Support\Identity;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\ActorRef;

/** TestUser-only typed resolver; unknown IDs fail closed. */
#[Internal]
final class FakeHostUserResolver implements HostUserResolver
{
    /**
     * @param list<int|string> $unknownIds ids that resolve to null
     */
    public function __construct(
        private readonly array $unknownIds = [],
    ) {}

    public function find(ActorRef $principal): ?Authorizable
    {
        $id = $principal->id;
        if ($principal->type !== (new TestUser)->getMorphClass() || $id === null || in_array($id, $this->unknownIds, strict: false)) {
            return null;
        }

        $user = new TestUser;
        $user->forceFill(['id' => Identity::key($id) ?? $id]);
        $user->exists = true;

        return $user;
    }
}
