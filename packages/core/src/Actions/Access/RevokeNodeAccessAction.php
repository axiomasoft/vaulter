<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Vaulter\Data\Access\RevokeGrantInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;

/** @internal Authorization belongs to AccessOperations; membership is also enforced here. */
final readonly class RevokeNodeAccessAction
{
    public function __construct(private VaulterDatabase $database, private NodeLocator $locator) {}

    public function execute(OperationContext $context, RevokeGrantInput $input): int
    {
        return $this->database->transaction(function () use ($context, $input): int {
            $node = $this->locator->find(context: $context, key: $input->node);
            $grant = $input->grant->newQuery()->where('node_id', $node->getKey())->whereKey($input->grant->getRawOriginal($input->grant->getKeyName()))->lockForUpdate()->first();
            if ($grant === null) {
                throw NodeNotFoundException::forId($node->id);
            }
            $subject = $grant->subject();
            $grant->delete();
            event(new NodeGrantRevoked(node: $node, subject: $subject, revoked: 1, actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return 1;
        });
    }
}
