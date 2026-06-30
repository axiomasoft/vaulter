<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\Access\GrantNodeAccessData;
use Vaulter\Events\NodeGrantCreated;
use Vaulter\Models\Node;
use Vaulter\Models\NodeGrant;
use Vaulter\Repositories\GrantStoreRepository;

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
        $grant = DB::transaction(fn (): NodeGrant => $this->grants->grant(node: $node, data: $data));

        event(new NodeGrantCreated(grant: $grant, actorId: $data->grantedBy));

        return $grant;
    }
}
