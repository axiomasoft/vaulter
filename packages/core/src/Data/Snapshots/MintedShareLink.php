<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots;

/**
 * A freshly minted share link: the only place the raw token ever appears. It is not
 * stored and cannot be read again.
 */
final readonly class MintedShareLink
{
    /** @internal */
    public function __construct(public ShareLinkSnapshot $link, public string $token) {}
}
