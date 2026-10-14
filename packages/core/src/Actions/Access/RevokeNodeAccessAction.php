<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Vaulter\Data\Access\RevokeGrantInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;

/** @internal Authorization belongs to AccessOperations; membership is also enforced here. */
final readonly class RevokeNodeAccessAction
{
    public function __construct(private VaulterDatabase $database, private NodeLocator $locator, private EventRecorder $recorder) {}

    public function execute(DriveContext $context, RevokeGrantInput $input): int
    {
        return $this->database->transaction(function () use ($context, $input): int {
            $node = $this->locator->find(context: $context, key: $input->node);
            $grant = $input->grant->newQuery()->where('node_id', $node->getKey())->whereKey($input->grant->getRawOriginal($input->grant->getKeyName()))->lockForUpdate()->first();
            if ($grant === null) {
                throw NodeNotFoundException::forId($node->id);
            }
            $subject = $grant->subject();
            $grant->delete();
            $this->recorder->record(new NodeGrantRevoked(node: $node, subject: $subject, revoked: 1, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return 1;
        });
    }
}
