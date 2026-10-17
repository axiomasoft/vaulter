<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Support;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;

/** The metric labels of the collaboration routes: the session and the hub calls (the RouteLabelSource contribution of the collaboration module). */
#[Internal]
final class CollaborationRouteLabels implements RouteLabelSource
{
    public function operations(): array
    {
        return [
            'vaulter.v1.documents.collaboration.session' => 'collab_issue',
            'vaulter.v1.collaboration.hub.store' => 'collab_persist',
        ];
    }

    public function operationPaths(): array
    {
        return [
            ['methods' => ['POST'], 'pattern' => 'v1/documents/{id}/collaboration/session', 'operation' => 'collab_issue'],
            ['methods' => ['POST'], 'pattern' => 'v1/collaboration/hub/store', 'operation' => 'collab_persist'],
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
