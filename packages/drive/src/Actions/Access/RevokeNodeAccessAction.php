<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Access;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Access\RevokeGrantInput;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Access\NodeGrantRevoked;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Events\EventRecorder;

/** @internal Authorization belongs to AccessOperations; membership is also enforced here. */
#[Internal]
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
            $this->recorder->record(EffectivePermissionsChanged::forNode(node: $node, reason: DriveEventType::GrantRevoked->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return 1;
        });
    }
}
