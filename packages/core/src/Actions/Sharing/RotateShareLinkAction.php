<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Vaulter\Data\Sharing\MintedShareLink;
use Vaulter\Data\Sharing\RotateShareLinkInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Sharing\NodeShareRotated;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Sharing\ShareTokenMinter;

/**
 * Replace the token of a live share link (P4-I22): the old token stops
 * resolving the moment this commits, the generation moves on so link sessions
 * opened with the old token are void, and everything else about the link — role,
 * audience, expiry, budget, password — stays. The new raw token is returned once.
 *
 * @internal
 */
final readonly class RotateShareLinkAction
{
    public function __construct(
        private ShareTokenMinter $minter,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private EventRecorder $recorder,
    ) {}

    public function execute(OperationContext $context, RotateShareLinkInput $input): MintedShareLink
    {
        return $this->database->transaction(function () use ($context, $input): MintedShareLink {
            $node = $this->locator->find(context: $context, key: $input->node);
            $share = $input->share->newQuery()->where('node_id', $node->getKey())
                ->whereKey($input->share->getRawOriginal($input->share->getKeyName()))->whereNull('revoked_at')->lockForUpdate()->first();
            if (!$share instanceof NodeShare) {
                throw NodeNotFoundException::forId($node->id);
            }

            [$rotated, $token] = $this->minter->mint(fn (string $tokenHash, string $tokenPrefix): NodeShare => $this->shares->rotate($share, $tokenHash, $tokenPrefix));
            $this->recorder->record(new NodeShareRotated(share: $rotated, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return new MintedShareLink(share: $rotated, token: $token);
        });
    }
}
