<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Operations;

use Vaulter\Attributes\Internal;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;

/**
 * The policies of the collaboration routes (D47, knowledge p6-design#coverage). Neither runs an operation of
 * its own: a session is a short-lived token issued without a write, and the hub protocol deduplicates by update id
 * and receipt and is fenced by the generation (D26). The policies name their routes.
 */
#[Internal]
final class CollaborationOperationPolicies implements OperationPolicySource
{
    public function policies(): array
    {
        return [
            'collaboration.sessions.create' => new OperationPolicy('collaboration.session', ConcurrencyPolicy::Natural, IdempotencyPolicy::Natural, ['documents.collaboration.session']),
            'collaboration.hub.protocol' => new OperationPolicy('collaboration.document_log', ConcurrencyPolicy::Generation, IdempotencyPolicy::Protocol, [
                'collaboration.hub.authenticate',
                'collaboration.hub.load',
                'collaboration.hub.renew',
                'collaboration.hub.append',
                'collaboration.hub.compaction.lease',
                'collaboration.hub.checkpoint',
                'collaboration.hub.recheck',
            ]),
        ];
    }
}
