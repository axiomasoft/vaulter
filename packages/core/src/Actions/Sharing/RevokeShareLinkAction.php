<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Data\Sharing\RevokeShareInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;

/** @internal Revocation commits before subsequent share resolution can succeed. */
final readonly class RevokeShareLinkAction
{
    public function __construct(private NodeShareStoreRepository $shares, private VaulterDatabase $database, private NodeLocator $locator, private EventRecorder $recorder) {}

    public function execute(DriveContext $context, RevokeShareInput $input): bool
    {
        return $this->database->transaction(function () use ($context, $input): bool {
            $node = $this->locator->find(context: $context, key: $input->node);
            $share = $input->share->newQuery()->where('node_id', $node->getKey())->whereKey($input->share->getRawOriginal($input->share->getKeyName()))->lockForUpdate()->first();
            if ($share === null) {
                throw NodeNotFoundException::forId($node->id);
            }
            $revoked = $this->shares->revoke(share: $share, now: Carbon::now());
            if ($revoked) {
                $this->recorder->record(new NodeShareRevoked(share: $share, actor: $context->actor->ref(), correlationId: $context->correlationId));
            }

            return $revoked;
        });
    }
}
