<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Sharing\MintedShareLink;
use Vaulter\Drive\Data\Sharing\RotateShareLinkInput;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Sharing\NodeShareRotated;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Drive\Sharing\ShareTokenMinter;
use Vaulter\Events\EventRecorder;

/**
 * Replace the token of a live share link (P4-I22): the old token stops
 * resolving the moment this commits, the generation moves on so link sessions
 * opened with the old token are void, and everything else about the link — role,
 * audience, expiry, budget, password — stays. The expected version is compared
 * under the link's row lock and the rotation raises it (CC1). The new raw token is
 * returned once.
 */
#[Internal]
final readonly class RotateShareLinkAction
{
    public function __construct(
        private ShareTokenMinter $minter,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private EventRecorder $recorder,
        private AggregateVersion $versions,
        private AccessSnapshotMapper $accessSnapshots,
    ) {}

    public function execute(DriveContext $context, RotateShareLinkInput $input): MintedShareLink
    {
        return $this->database->transaction(function () use ($context, $input): MintedShareLink {
            $node = $this->locator->find(context: $context, key: $input->node);
            $share = $input->share->newQuery()->where('node_id', $node->getKey())
                ->whereKey($input->share->getRawOriginal($input->share->getKeyName()))->whereNull('revoked_at')->lockForUpdate()->first();
            if (!$share instanceof NodeShare) {
                throw NodeNotFoundException::forId($node->id);
            }
            $this->versions->assertExpected($share, 'version', $input->expectedVersion, AggregateVersion::SHARE);

            [$rotated, $token] = $this->minter->mint(fn (string $tokenHash, string $tokenPrefix): NodeShare => $this->shares->rotate($share, $tokenHash, $tokenPrefix));
            $this->versions->bump($rotated, 'version', AggregateVersion::SHARE);
            $this->recorder->record(new NodeShareRotated(accessSnapshots: $this->accessSnapshots, share: $rotated, actor: $context->actor->ref(), correlationId: $context->correlationId));
            $this->recorder->record(EffectivePermissionsChanged::forNode(node: $node, reason: DriveEventType::ShareRotated->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return new MintedShareLink(share: $rotated, token: $token);
        });
    }
}
