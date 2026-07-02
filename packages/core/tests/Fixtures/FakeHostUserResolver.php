<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\DocDrive\Contracts\Collaboration\HostUserResolver;
use Vaulter\Support\Identity;

/**
 * A test {@see HostUserResolver} (M4b): materialises a TestUser for any id without a
 * configured auth user-provider, so the collaboration webhook's authenticate flow can
 * run the AccessResolver against a real actor in tests. An id present in $unknownIds
 * resolves to null (to exercise the allowed=false path).
 */
final class FakeHostUserResolver implements HostUserResolver
{
    /**
     * @param list<int|string> $unknownIds ids that resolve to null
     */
    public function __construct(
        private readonly array $unknownIds = [],
    ) {}

    public function find(int|string $id): ?Authorizable
    {
        if (in_array($id, $this->unknownIds, strict: false)) {
            return null;
        }

        $user = new TestUser;
        $user->forceFill(['id' => Identity::key($id) ?? $id]);
        $user->exists = true;

        return $user;
    }
}
