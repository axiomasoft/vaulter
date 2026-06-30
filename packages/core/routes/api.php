<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Vaulter\Http\Controllers\Activity\ActivityController;
use Vaulter\Http\Controllers\BulkDocumentController;
use Vaulter\Http\Controllers\CollaborationController;
use Vaulter\Http\Controllers\CommentController;
use Vaulter\Http\Controllers\DocumentController;
use Vaulter\Http\Controllers\Drive\DocumentNodeController;
use Vaulter\Http\Controllers\Drive\DownloadController;
use Vaulter\Http\Controllers\Drive\GrantController;
use Vaulter\Http\Controllers\Drive\NodeCollaborationController;
use Vaulter\Http\Controllers\Drive\NodeController;
use Vaulter\Http\Controllers\Drive\StorageController;
use Vaulter\Http\Controllers\ExportController;
use Vaulter\Http\Controllers\FolderController;
use Vaulter\Http\Controllers\PermissionController;
use Vaulter\Http\Controllers\ShareController;
use Vaulter\Http\Controllers\Sharing\PublicShareController;
use Vaulter\Http\Controllers\Sharing\ShareController as NodeShareController;
use Vaulter\Http\Controllers\Uploads\S3MultipartController;
use Vaulter\Http\Controllers\Uploads\TusUploadController;
use Vaulter\Http\Controllers\VersionController;
use Vaulter\Http\Controllers\Webhooks\WebhookEndpointsController;
use Vaulter\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Support\Config;

/*
|--------------------------------------------------------------------------
| Vaulter API routes
|--------------------------------------------------------------------------
| Mounted under the configured prefix (default: api/vaulter).
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::middleware())
    ->group(static function (): void {
        // Documents
        Route::get('documents', [DocumentController::class, 'index']); // supports ?q= search
        Route::get('documents/trash', [DocumentController::class, 'trash']); // before /{id}
        Route::post('documents/bulk', BulkDocumentController::class);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::patch('documents/{id}', [DocumentController::class, 'update']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);
        Route::delete('documents/{id}/force', [DocumentController::class, 'forceDestroy']);
        Route::post('documents/{id}/restore', [DocumentController::class, 'restore']);
        Route::patch('documents/{id}/move', [DocumentController::class, 'move']);
        Route::post('documents/{id}/duplicate', [DocumentController::class, 'duplicate']);
        Route::get('documents/{documentId}/export', [ExportController::class, 'show']);

        // Versions
        Route::get('documents/{documentId}/versions', [VersionController::class, 'index']);
        Route::post('documents/{documentId}/versions', [VersionController::class, 'store']);
        Route::post('documents/{documentId}/versions/{versionId}/restore', [VersionController::class, 'restore']);

        // Permissions
        Route::get('documents/{documentId}/permissions', [PermissionController::class, 'index']);
        Route::post('documents/{documentId}/permissions', [PermissionController::class, 'store']);
        Route::delete('documents/{documentId}/permissions/{permissionId}', [PermissionController::class, 'destroy']);

        // Comments
        Route::get('documents/{documentId}/comments', [CommentController::class, 'index']);
        Route::post('documents/{documentId}/comments', [CommentController::class, 'store']);
        Route::post('documents/{documentId}/comments/{commentId}/resolve', [CommentController::class, 'resolve']);
        Route::delete('documents/{documentId}/comments/{commentId}', [CommentController::class, 'destroy']);

        // Share links
        Route::get('documents/{documentId}/shares', [ShareController::class, 'index']);
        Route::post('documents/{documentId}/shares', [ShareController::class, 'store']);
        Route::delete('documents/{documentId}/shares/{shareId}', [ShareController::class, 'destroy']);
        Route::get('shares/{token}', [ShareController::class, 'resolve']);

        // Collaboration
        Route::post('documents/{documentId}/collab/token', [CollaborationController::class, 'token']);
        Route::post('documents/{documentId}/collab/sync', [CollaborationController::class, 'sync']);

        // Folders
        Route::get('folders', [FolderController::class, 'index']);
        Route::post('folders', [FolderController::class, 'store']);
        Route::patch('folders/{id}', [FolderController::class, 'update']);
        Route::delete('folders/{id}', [FolderController::class, 'destroy']);

        /*
        |----------------------------------------------------------------------
        | Drive (M1c) — the unified v_nodes file/folder tree
        |----------------------------------------------------------------------
        | {storage} and {node} resolve the swappable Node/Storage models via the
        | Route::bind registered in the service provider (config('vaulter.models.*')).
        | SubstituteBindings is applied explicitly so the route-model binding runs
        | even when a host trims its api middleware group (it is idempotent when the
        | host's group already includes it).
        |
        | Storage scope: storage-collection endpoints (index/search/trash/folders/
        | uploads) carry the {storage} id in the path; node-scoped endpoints derive
        | the storage from the bound node. The fixed sub-segments (search, trash,
        | force, download) sit before the catch-all /{node} so they never shadow it.
        */
        Route::middleware(SubstituteBindings::class)->group(static function (): void {
            Route::get('storages/{storage}', [StorageController::class, 'show']);

            Route::get('storages/{storage}/nodes', [NodeController::class, 'index']);
            Route::get('storages/{storage}/search', [NodeController::class, 'search']);
            Route::get('storages/{storage}/trash', [NodeController::class, 'trashed']);
            Route::post('storages/{storage}/folders', [NodeController::class, 'storeFolder']);
            Route::post('storages/{storage}/uploads', [NodeController::class, 'upload']);

            /*
            |------------------------------------------------------------------
            | DocDrive — document nodes (§2.5 / §7 / M4a)
            |------------------------------------------------------------------
            | Create a kind=document node, read/autosave its content, snapshot
            | named versions, restore a prior version, resolve its handler
            | descriptor and export it. Gated on the 'document_versions' feature
            | (the v_node_document_versions schema). The fixed sub-segments
            | (content/versions/handler/export) sit BEFORE the catch-all /{node}
            | routes below so they never shadow them. Authorized via the
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
                Route::get('nodes/{node}/handler', [DocumentNodeController::class, 'handler']);
                Route::get('nodes/{node}/export', [DocumentNodeController::class, 'export']);

                // Realtime collaboration connect handshake (§7 / ADR-0001 / M4b):
                // authorized via the AccessResolver (≥ View/Comment to connect,
                // read-only unless Edit), returning the transport-agnostic
                // CollaborationSession descriptor. The fixed /collab/token segment
                // sits before the catch-all /{node} routes so it never shadows them.
                Route::post('nodes/{node}/collab/token', [NodeCollaborationController::class, 'token']);
            }

            /*
            |------------------------------------------------------------------
            | Resumable uploads (§2.8 / D16 / M3a)
            |------------------------------------------------------------------
            | Large files upload resumably, then the assembled staged bytes go
            | through the SAME race-free BlobStore.commit (H.3). Gated on the
            | 'uploads' feature. {upload_id} is the client UNIQUE handle (a plain
            | scalar, NOT route-model bound — the controller resolves the session
            | and asserts it belongs to an authorized target). tus group: create
            | (POST), HEAD (Upload-Offset), PATCH (chunk), DELETE (abort), status
            | (GET). S3 group: parts (POST), complete (POST). Authorized via
            | AccessResolver — Create in the target folder / Edit for a new version.
            */
            if (Config::featureEnabled('uploads')) {
                // tus.io CORE — create lives under the storage; the resumable ops
                // address the session by its upload_id handle.
                Route::post('storages/{storage}/resumable-uploads', [TusUploadController::class, 'create']);

                Route::get('resumable-uploads/{upload_id}', [TusUploadController::class, 'status']);
                Route::match(['head'], 'resumable-uploads/{upload_id}', [TusUploadController::class, 'head']);
                Route::patch('resumable-uploads/{upload_id}', [TusUploadController::class, 'patch']);
                Route::delete('resumable-uploads/{upload_id}', [TusUploadController::class, 'abort']);

                // S3-style multipart — parts + complete on the same session handle.
                Route::post('resumable-uploads/{upload_id}/parts', [S3MultipartController::class, 'parts']);
                Route::post('resumable-uploads/{upload_id}/complete', [S3MultipartController::class, 'complete']);
            }

            // Append-only activity feed (§2.9 / M2c-activity). Recipients resolved at
            // READ by scoping to the storage/node — no notification rows. Authorized
            // via AccessResolver Ability::View on the storage root / node. The fixed
            // /activity segment sits before the catch-all /{node}.
            Route::get('storages/{storage}/activity', [ActivityController::class, 'forStorage']);

            Route::get('nodes/{node}', [NodeController::class, 'show']);
            Route::patch('nodes/{node}', [NodeController::class, 'rename']);
            Route::post('nodes/{node}/move', [NodeController::class, 'move']);
            Route::post('nodes/{node}/copy', [NodeController::class, 'copy']);
            Route::post('nodes/{node}/restore', [NodeController::class, 'restore']);
            Route::delete('nodes/{node}/force', [NodeController::class, 'forceDelete']);
            Route::delete('nodes/{node}', [NodeController::class, 'trash']);

            Route::get('nodes/{node}/download', [DownloadController::class, 'current']);
            Route::get('nodes/{node}/versions/{version}/download', [DownloadController::class, 'version']);

            // Per-node ACL grants (§2.6 / H.2). The fixed /grants segment sits before
            // the catch-all /{node} so it never shadows the node routes above.
            Route::get('nodes/{node}/grants', [GrantController::class, 'index']);
            Route::post('nodes/{node}/grants', [GrantController::class, 'store']);
            Route::delete('nodes/{node}/grants', [GrantController::class, 'destroy']);

            // Bearer share-links — the AUTHENTICATED OWNER surface (§2.7 / M2c).
            // Authorized via AccessResolver Ability::Share on the node. create()
            // returns the raw token once; index() never exposes it. The fixed
            // /shares segment sits before the catch-all /{node}. The {share} id is a
            // plain scalar (not route-model bound) — the controller verifies it
            // belongs to the authorized node before revoking.
            Route::get('nodes/{node}/shares', [NodeShareController::class, 'index']);
            Route::post('nodes/{node}/shares', [NodeShareController::class, 'store']);
            Route::delete('nodes/{node}/shares/{share}', [NodeShareController::class, 'destroy']);

            // Per-node activity feed (§2.9 / M2c-activity). Authorized via
            // AccessResolver Ability::View on the node. The fixed /activity segment
            // sits before the catch-all /{node}.
            Route::get('nodes/{node}/activity', [ActivityController::class, 'forNode']);
        });

        /*
        |----------------------------------------------------------------------
        | Realtime collaboration webhooks (§7 / ADR-0001 / M4b)
        |----------------------------------------------------------------------
        | The SERVER-TO-SERVER surface the Node Hocuspocus hub calls back into:
        | onAuthenticate → collab/authenticate, onStoreDocument → collab/store.
        | These carry NO auth middleware — only ResolveCollaborationCall, which
        | authenticates the caller via the shared webhook secret OR a valid signed
        | room JWT (never open) and stashes the resolved call. The node is resolved
        | from the BODY (room/node_id), not route-model bound, and a JWT call may
        | only address its own room (a token for node A cannot store to node B).
        | Gated on the 'document_versions' feature.
        */
        if (Config::featureEnabled('document_versions')) {
            Route::middleware(ResolveCollaborationCall::class)->group(static function (): void {
                Route::post('collab/authenticate', [NodeCollaborationController::class, 'authenticate']);
                Route::post('collab/store', [NodeCollaborationController::class, 'store']);
            });
        }

        /*
        |----------------------------------------------------------------------
        | Webhook-endpoint management (§2.11 / D14 / M3b)
        |----------------------------------------------------------------------
        | The GLOBAL admin surface for subscriber endpoints — register/list/update/
        | deactivate/delete. NOT node-scoped: every action is gated by the
        | WebhookEndpointPolicy manage ability (Gate::authorize in the controller).
        | register() returns the signing_secret exactly once. Gated on the
        | 'reliability' feature so the routes only mount when the outbox/webhook schema
        | exists. The {endpoint} id is a plain scalar (the model is swappable, not
        | route-model bound) — the controller resolves it via the read repository.
        */
        if (Config::featureEnabled('reliability')) {
            Route::get('webhook-endpoints', [WebhookEndpointsController::class, 'index']);
            Route::post('webhook-endpoints', [WebhookEndpointsController::class, 'store']);
            Route::patch('webhook-endpoints/{endpoint}', [WebhookEndpointsController::class, 'update']);
            Route::post('webhook-endpoints/{endpoint}/deactivate', [WebhookEndpointsController::class, 'deactivate']);
            Route::delete('webhook-endpoints/{endpoint}', [WebhookEndpointsController::class, 'destroy']);
        }

        /*
        |----------------------------------------------------------------------
        | Bearer share-links — the PUBLIC, token-resolved surface (§2.7 / M2c)
        |----------------------------------------------------------------------
        | The SEPARATE sharing channel. These routes carry NO auth middleware —
        | only ResolveShareToken, which hashes the {token}, resolves the LIVE
        | share, runs the timing-safe compare + password / require-login gates,
        | intersects with item-safety and stashes a ShareContext. A share grants
        | EXACTLY its bitmask on its node-set — the identity ACL is never consulted
        | here. An optional already-authenticated host user satisfies require_login.
        */
        Route::middleware(ResolveShareToken::class)->group(static function (): void {
            Route::get('share/{token}', [PublicShareController::class, 'show']);
            Route::get('share/{token}/nodes', [PublicShareController::class, 'nodes']);
            Route::get('share/{token}/download', [PublicShareController::class, 'download']);
        });
    });
