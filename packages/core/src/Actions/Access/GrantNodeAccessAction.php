<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Access\GrantStoreRepository;

/**
 * Grant (or deny) a subject access to a node (§2.6 / H.2). Idempotent on the
 * grant-unique key: re-granting the same subject the same scope/effect updates the
 * level/bits/expiry. The write runs in a transaction and emits NodeGrantCreated.
 *
 * Authorization (the actor may Share/Manage the node) is enforced by the caller
 * (controller/policy) before reaching the Action — the Action persists, it does
 * not re-authorize.
 */
final readonly class GrantNodeAccessAction
{
    public function __construct(
        private GrantStoreRepository $grants,
    ) {}

    public function execute(Node $node, GrantNodeAccessData $data): NodeGrant
    {
        // The event fires INSIDE the transaction (F-P1.7-03): the outbox/activity rows
        // stage in the SAME commit as the grant — no crash window between commit and
        // staging. The subscribers swallow their own failures, so staging still never
        // rolls the grant back.
        return DB::transaction(function () use ($node, $data): NodeGrant {
            $grant = $this->grants->grant(node: $node, data: $data);

            event(new NodeGrantCreated(grant: $grant, actorId: $data->grantedBy));

            return $grant;
        });
    }
}
