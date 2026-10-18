<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Sharing\RevokeShareInput;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Sharing\NodeShareRevoked;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Events\EventRecorder;

/**
 * @internal Revocation commits before subsequent share resolution can succeed. The expected version is compared
 * under the link's row lock and a revocation raises it (CC1); revoking a revoked link changes nothing.
 */
#[Internal]
final readonly class RevokeShareLinkAction
{
    public function __construct(private NodeShareStoreRepository $shares, private VaulterDatabase $database, private NodeLocator $locator, private EventRecorder $recorder, private AggregateVersion $versions) {}

    public function execute(DriveContext $context, RevokeShareInput $input): bool
    {
        return $this->database->transaction(function () use ($context, $input): bool {
            $node = $this->locator->find(context: $context, key: $input->node);
            $share = $input->share->newQuery()->where('node_id', $node->getKey())->whereKey($input->share->getRawOriginal($input->share->getKeyName()))->lockForUpdate()->first();
            if ($share === null) {
                throw NodeNotFoundException::forId($node->id);
            }
            $this->versions->assertExpected($share, 'version', $input->expectedVersion, AggregateVersion::SHARE);
            $revoked = $this->shares->revoke(share: $share, now: Carbon::now());
            if ($revoked) {
                $this->versions->bump($share, 'version', AggregateVersion::SHARE);
                $this->recorder->record(new NodeShareRevoked(share: $share, actor: $context->actor->ref(), correlationId: $context->correlationId));
                $this->recorder->record(EffectivePermissionsChanged::forNode(node: $node, reason: DriveEventType::ShareRevoked->value, actor: $context->actor->ref(), correlationId: $context->correlationId));
            }

            return $revoked;
        });
    }
}
