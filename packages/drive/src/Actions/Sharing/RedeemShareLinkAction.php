<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Sharing;

use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Repositories\Sharing\NodeShareStoreRepository;

/**
 * Count one successful opening of a share link. A redemption is the
 * start of a link session, not a request: the visits that follow inside the session
 * do not count. The count is one conditional UPDATE, so a link at its budget, a
 * revoked link or a link rotated since it was resolved is refused atomically.
 */
#[Internal]
final readonly class RedeemShareLinkAction
{
    public function __construct(private NodeShareStoreRepository $shares, private VaulterDatabase $database) {}

    public function execute(NodeShare $share): bool
    {
        return $this->database->write(fn (): bool => $this->shares->consumeUse($share));
    }
}
