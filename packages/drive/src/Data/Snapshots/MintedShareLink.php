<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * A freshly minted share link: the only place the raw token ever appears. It is not
 * stored and cannot be read again.
 */
#[Api]
final readonly class MintedShareLink
{
    #[Internal]
    public function __construct(public ShareLinkSnapshot $link, public string $token) {}
}
