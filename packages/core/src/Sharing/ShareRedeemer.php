<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Illuminate\Support\Carbon;
use Vaulter\Actions\Sharing\RedeemShareLinkAction;
use Vaulter\Models\Sharing\NodeShare;

/**
 * Opens a visit on a resolved share link (PLAN2.D47): counts the redemption, then
 * hands out the link session that makes the visitor's next requests part of the same
 * visit. The public HTTP layer talks to this instead of to the Action.
 */
final readonly class ShareRedeemer
{
    public function __construct(private RedeemShareLinkAction $redeem, private ShareSession $sessions) {}

    /** Count one use; false when the link was revoked, rotated or spent since it was resolved. */
    public function redeem(NodeShare $share): bool
    {
        return $this->redeem->execute($share);
    }

    /** The session for a freshly redeemed link, or null when the application cannot sign one. */
    public function session(NodeShare $share): ?string
    {
        return $this->sessions->issue($share, Carbon::now());
    }
}
