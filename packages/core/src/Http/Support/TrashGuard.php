<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Vaulter\Models\Drive\Node;

/**
 * The ONE shared item-safety trash-check for the IDENTITY read/download/export
 * surface (P2.2 / F-P1.12-01) — the download/export twin of
 * {@see ShareResolver} 's `safeTargetNode()` trash guard on the
 * SHARE channel. A node sitting in the drive trash (`trashed_at`) must be
 * unreadable through the identity
 * channel too, even when the actor's ACL grant on it is still live: trash is
 * item-safety, ORTHOGONAL to the ACL (H.2), so this stays out of AccessResolver
 * and instead gates the two PHP-stream/content controllers directly — the
 * identity DownloadController (vaulter-drive) and DocumentNodeController
 * (vaulter-documents). Referenced from both, never the other way — core must not
 * depend on either extracted package.
 *
 * Route-model binding deliberately still resolves a trashed node (the restore
 * and force-delete surfaces need it, see VaulterServiceProvider::registerRouteBindings);
 * this guard is the item-safety layer that sits in FRONT of the read/download/
 * export controllers only, so restore/forceDelete stay unaffected.
 */
final class TrashGuard
{
    /**
     * 404 when the node is sitting in the trash — opaque, indistinguishable from
     * a missing node, the same posture the share channel already takes.
     */
    public static function assertNotTrashed(Node $node): void
    {
        if ($node->trashed_at !== null) {
            abort(404);
        }
    }
}
