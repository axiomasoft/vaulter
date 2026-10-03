<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Vaulter\Documents\Http\Controllers\DocumentNodeController;
use Vaulter\Documents\Http\Controllers\NodeCollaborationController;
use Vaulter\Documents\Http\Controllers\NodeCommentController;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Http\Middleware\ResolveVaulterRouteParameters;
use Vaulter\Support\Config;

/*
|--------------------------------------------------------------------------
| Vaulter Documents API routes (P2 #5 / ADR-0015, D2)
|--------------------------------------------------------------------------
| The document-node surface, extracted from core's routes/api.php into the
| vaulter-documents package. Mounted under the SAME configured prefix (default:
| api/vaulter) and middleware as the kernel routes — Laravel merges the group
| registrations across files. Local resource middleware resolves {storage} and
| {node} through swappable core repositories before SubstituteBindings. The fixed sub-segments (content/versions/handler/export/collab/
| comments) differ in segment count from core's and vaulter-drive's catch-all
| /{node}, so cross-file registration order never shadows them.
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::authenticatedMiddleware())
    ->group(static function (): void {
        Route::middleware([ResolveVaulterRouteParameters::class, SubstituteBindings::class])->group(static function (): void {
            /*
            |------------------------------------------------------------------
            | Documents — document nodes (§2.5 / §7 / M4a)
            |------------------------------------------------------------------
            | Create a kind=document node, read/autosave its content, snapshot
            | named versions, restore a prior version, resolve its handler
            | descriptor and export it. Gated on the 'document_versions' feature
            | (the v_node_document_versions schema). Authorized via the
            | AccessResolver (Create on the destination, View to read, Edit to
            | write).
            */
            if (Config::featureEnabled('document_versions')) {
                Route::post('storages/{storage}/documents', [DocumentNodeController::class, 'store']);

                Route::get('nodes/{node}/content', [DocumentNodeController::class, 'content']);
                Route::patch('nodes/{node}/content', [DocumentNodeController::class, 'update']);
                Route::get('nodes/{node}/document-versions', [DocumentNodeController::class, 'versions']);
                Route::post('nodes/{node}/document-versions', [DocumentNodeController::class, 'storeVersion']);
                Route::post('nodes/{node}/document-versions/{version}/restore', [DocumentNodeController::class, 'restore']);
                // Release a keep_forever pin (F-P1.12-03) — the missing inverse of
                // the pin `storeVersion` can set; the version re-enters the prune
                // window on the node's next snapshot/restore.
                Route::patch('nodes/{node}/document-versions/{version}/keep-forever', [DocumentNodeController::class, 'releaseVersion']);
                Route::get('nodes/{node}/handler', [DocumentNodeController::class, 'handler']);
                Route::get('nodes/{node}/export', [DocumentNodeController::class, 'export']);
            }

            // Realtime collaboration connect handshake (§7 / ADR-0001 / M4b):
            // authorized via the AccessResolver (≥ View/Comment to connect, read-only
            // unless Edit), returning the transport-agnostic CollaborationSessionData
            // descriptor. Gated on BOTH 'document_versions' (the schema the session
            // reads/writes against) AND 'collaboration' (VLT-REMED P3.1 / F-P1.6-01,
            // D13/D20) — the realtime surface is a SEPARATE opt-in over base
            // (non-realtime) document editing, not implied by it.
            if (Config::featureEnabled('document_versions') && Config::collaborationEnabled()) {
                Route::post('nodes/{node}/collab/token', [NodeCollaborationController::class, 'token']);
            }

            // NODE-attached comments (ADR-0014 шаг 1) — the additive twin of the
            // legacy document_id-bound comments. Gated on the 'comments' feature.
            // Authorized via AccessResolver: read ≥ View, create = Comment, resolve/
            // delete = the AUTHOR or Manage. {comment} is a plain scalar (the model is
            // swappable, not route-model bound) — the controller resolves it and
            // asserts it belongs to the bound node.
            if (Config::featureEnabled('comments')) {
                Route::get('nodes/{node}/comments', [NodeCommentController::class, 'index']);
                Route::post('nodes/{node}/comments', [NodeCommentController::class, 'store']);
                Route::post('nodes/{node}/comments/{comment}/resolve', [NodeCommentController::class, 'resolve']);
                Route::delete('nodes/{node}/comments/{comment}', [NodeCommentController::class, 'destroy']);
            }
        });

    });

if (Config::featureEnabled('document_versions') && Config::collaborationEnabled()) {
    Route::prefix(Config::routePrefix())
        ->middleware([...Config::hubMiddleware(), 'throttle:vaulter-public', ResolveCollaborationCall::class])
        ->group(static function (): void {
            Route::post('collab/authenticate', [NodeCollaborationController::class, 'authenticate']);
            Route::post('collab/store', [NodeCollaborationController::class, 'store']);
        });
}
