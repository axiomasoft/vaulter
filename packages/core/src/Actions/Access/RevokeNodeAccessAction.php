<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Facades\DB;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Events\NodeGrantRevoked;
use Vaulter\Models\Node;
use Vaulter\Repositories\GrantStoreRepository;

/**
 * Revoke every grant a subject holds on a node (§2.6 / H.2): both effects, both
 * scopes. Returns the count removed; emits NodeGrantRevoked only when something was
 * actually revoked (an empty revoke is a silent no-op). Transactional.
 */
final readonly class RevokeNodeAccessAction
{
    public function __construct(
        private GrantStoreRepository $grants,
    ) {}

    public function execute(Node $node, Subject $subject, int|string|null $actorId = null): int
    {
        $revoked = DB::transaction(fn (): int => $this->grants->revoke(node: $node, subject: $subject));

        if ($revoked > 0) {
            event(new NodeGrantRevoked(
                node: $node,
                subject: $subject,
                revoked: $revoked,
                actorId: $actorId,
            ));
        }

        return $revoked;
    }
}
