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
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\NodeStoreRepository;
use Vaulter\Events\EventRecorder;

/**
 * @internal Authorization belongs to AccessOperations; membership is also enforced here. The node row is locked
 * first (then the grant row), the expected access_version is compared under that lock and the revoke raises it
 * (CC1); the node sequence does not move (CC2).
 */
#[Internal]
final readonly class RevokeNodeAccessAction
{
    public function __construct(private VaulterDatabase $database, private NodeLocator $locator, private EventRecorder $recorder, private NodeStoreRepository $nodes, private AggregateVersion $versions) {}

    public function execute(DriveContext $context, RevokeGrantInput $input): int
    {
        return $this->database->transaction(function () use ($context, $input): int {
            $node = $this->locator->find(context: $context, key: $input->node);
            $locked = $this->nodes->lockForUpdate([$node->id])->first() ?? throw NodeNotFoundException::forId($node->id);
            $this->versions->assertExpected($locked, 'access_version', $input->expectedAccessVersion, AggregateVersion::NODE_ACCESS);
            $grant = $input->grant->newQuery()->where('node_id', $locked->getKey())->whereKey($input->grant->getRawOriginal($input->grant->getKeyName()))->lockForUpdate()->first();
            if ($grant === null) {
                throw NodeNotFoundException::forId($node->id);
            }
            $subject = $grant->subject();
            $grant->delete();
            $this->versions->bump($locked, 'access_version', AggregateVersion::NODE_ACCESS);
            $this->recorder->record(new NodeGrantRevoked(node: $locked, subject: $subject, revoked: 1, actor: $context->actor->ref(), correlationId: $context->correlationId));
            $this->recorder->record(EffectivePermissionsChanged::forNode(node: $locked, reason: DriveEventType::GrantRevoked->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return 1;
        });
    }
}
