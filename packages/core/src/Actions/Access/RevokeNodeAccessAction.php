<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Facades\DB;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantStoreRepository;

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
        // The event fires INSIDE the transaction (F-P1.7-03) so the outbox/activity
        // rows stage atomically with the revoke — no crash window after commit.
        return DB::transaction(function () use ($node, $subject, $actorId): int {
            $revoked = $this->grants->revoke(node: $node, subject: $subject);

            if ($revoked > 0) {
                event(new NodeGrantRevoked(
                    node: $node,
                    subject: $subject,
                    revoked: $revoked,
                    actorId: $actorId,
                ));
            }

            return $revoked;
        });
    }
}
