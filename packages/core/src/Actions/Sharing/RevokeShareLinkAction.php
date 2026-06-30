<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;

/**
 * Revoke a bearer share-link (§2.7 / M2c). Stamps revoked_at so the share never
 * resolves again (the resolver filters on revoked_at). Idempotent — a re-revoke of
 * an already-revoked share is a silent no-op and emits nothing. Transactional.
 *
 * Authorization (the actor may Share the node) is enforced by the caller before
 * reaching the Action.
 */
final readonly class RevokeShareLinkAction
{
    public function __construct(
        private NodeShareStoreRepository $shares,
    ) {}

    public function execute(NodeShare $share, int|string|null $actorId = null): bool
    {
        $revoked = DB::transaction(
            fn (): bool => $this->shares->revoke(share: $share, now: Carbon::now()),
        );

        if ($revoked) {
            event(new NodeShareRevoked(share: $share, actorId: $actorId));
        }

        return $revoked;
    }
}
