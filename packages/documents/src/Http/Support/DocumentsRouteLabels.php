<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Support;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;

/** The metric labels of the documents' routes: documents, comments and the collaboration hub (the RouteLabelSource contribution of the documents module). */
#[Internal]
final class DocumentsRouteLabels implements RouteLabelSource
{
    public function operations(): array
    {
        return [
            'vaulter.v1.documents.store' => 'doc_write',
            'vaulter.v1.documents.content.show' => 'doc_read',
            'vaulter.v1.documents.content.update' => 'doc_write',
            'vaulter.v1.documents.revisions.index' => 'doc_read',
            'vaulter.v1.documents.revisions.show' => 'doc_read',
            'vaulter.v1.documents.revisions.store' => 'doc_write',
            'vaulter.v1.documents.revisions.restore' => 'doc_write',
            'vaulter.v1.documents.revisions.update' => 'doc_write',
            'vaulter.v1.documents.export' => 'doc_export',
            'vaulter.v1.documents.collaboration.session' => 'collab_issue',
            'vaulter.v1.collaboration.hub.store' => 'collab_persist',
            'vaulter.v1.comments.index' => 'comment',
            'vaulter.v1.comments.store' => 'comment',
            'vaulter.v1.comments.resolve' => 'comment',
            'vaulter.v1.comments.destroy' => 'comment',
        ];
    }

    public function operationPaths(): array
    {
        return [
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/content', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/revisions', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/revisions/{id}', 'operation' => 'doc_read'],
            ['methods' => ['PUT'], 'pattern' => 'v1/documents/{id}/content', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/drives/{id}/documents', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/revisions', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/revisions/{id}/restore', 'operation' => 'doc_write'],
            ['methods' => ['PATCH'], 'pattern' => 'v1/documents/{id}/revisions/{id}', 'operation' => 'doc_write'],
            ['methods' => ['GET'], 'pattern' => 'v1/documents/{id}/export/{id}', 'operation' => 'doc_export'],
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/collaboration/session', 'operation' => 'collab_issue'],
            ['methods' => ['POST'], 'pattern' => 'v1/collaboration/hub/store', 'operation' => 'collab_persist'],
            ['methods' => ['GET', 'POST'], 'pattern' => 'v1/nodes/{id}/comments', 'operation' => 'comment'],
            ['methods' => ['POST', 'DELETE'], 'pattern' => 'v1/nodes/{id}/comments/{id}', 'operation' => 'comment'],
            ['methods' => ['POST'], 'pattern' => 'v1/nodes/{id}/comments/{id}/resolve', 'operation' => 'comment'],
        ];
    }

    public function throttled(): array
    {
        return [
            'vaulter.v1.collaboration.hub.authenticate' => 'collab.authenticate',
            'vaulter.v1.collaboration.hub.store' => 'collab.store',
        ];
    }
}
