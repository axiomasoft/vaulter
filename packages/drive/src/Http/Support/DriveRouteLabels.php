<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Packman\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;

/** The metric labels of the drive's routes (the RouteLabelSource contribution of the drive module). */
#[Internal]
final class DriveRouteLabels implements RouteLabelSource
{
    public function operations(): array
    {
        return [
            'vaulter.v1.activity.drive' => 'activity_feed',
            'vaulter.v1.activity.node' => 'activity_feed',
            'vaulter.v1.public-shares.download' => 'download',
            'vaulter.v1.drives.show' => 'point_read',
            'vaulter.v1.nodes.show' => 'point_read',
            'vaulter.v1.nodes.index' => 'tree_list',
            'vaulter.v1.nodes.starred' => 'tree_list',
            'vaulter.v1.trash.index' => 'tree_list',
            'vaulter.v1.shared.index' => 'tree_list',
            'vaulter.v1.nodes.search' => 'search',
            'vaulter.v1.folders.store' => 'tree_mutate',
            'vaulter.v1.nodes.update' => 'tree_mutate',
            'vaulter.v1.nodes.properties.update' => 'tree_mutate',
            'vaulter.v1.nodes.copy' => 'tree_mutate',
            'vaulter.v1.nodes.trash' => 'tree_mutate',
            'vaulter.v1.nodes.restore' => 'tree_mutate',
            'vaulter.v1.nodes.purge' => 'tree_mutate',
            'vaulter.v1.trash.empty' => 'tree_mutate',
            'vaulter.v1.nodes.move' => 'subtree_mutate',
            'vaulter.v1.uploads.store' => 'upload_session',
            'vaulter.v1.uploads.show' => 'upload_session',
            'vaulter.v1.uploads.tus.head' => 'upload_session',
            'vaulter.v1.uploads.tus.destroy' => 'upload_session',
            'vaulter.v1.uploads.tus.options' => 'upload_session',
            'vaulter.v1.uploads.tus.patch' => 'tus_patch',
            'vaulter.v1.uploads.parts.store' => 's3_putpart',
            'vaulter.v1.uploads.complete' => 's3_complete',
            'vaulter.v1.files.store' => 'single_shot',
            'vaulter.v1.files.revisions.store' => 'single_shot',
            'vaulter.v1.files.download' => 'download',
            'vaulter.v1.files.revisions.download' => 'download',
        ];
    }

    public function operationPaths(): array
    {
        return [
            ['methods' => ['GET'], 'pattern' => 'v1/files/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'v1/files/{id}/revisions/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'v1/shares/{id}/download', 'operation' => 'download'],
            ['methods' => ['POST'], 'pattern' => 'v1/drives/{id}/files', 'operation' => 'single_shot'],
            ['methods' => ['POST'], 'pattern' => 'v1/files/{id}/revisions', 'operation' => 'single_shot'],
            ['methods' => ['PATCH'], 'pattern' => 'v1/uploads/{id}', 'operation' => 'tus_patch'],
            ['methods' => ['POST'], 'pattern' => 'v1/uploads/{id}/parts', 'operation' => 's3_putpart'],
        ];
    }

    public function throttled(): array
    {
        return [
            'vaulter.v1.public-shares.show' => 'share.show',
            'vaulter.v1.public-shares.nodes' => 'share.nodes',
            'vaulter.v1.public-shares.download' => 'share.download',
            'vaulter.v1.public-shares.unlock' => 'share.unlock',
            'vaulter.v1.uploads.store' => 'uploads.tus_create',
            'vaulter.v1.uploads.tus.patch' => 'uploads.tus_patch',
            'vaulter.v1.uploads.show' => 'uploads.tus_status',
            'vaulter.v1.uploads.tus.head' => 'uploads.tus_status',
            'vaulter.v1.uploads.parts.store' => 'uploads.s3_parts',
            'vaulter.v1.uploads.complete' => 'uploads.s3_complete',
            'vaulter.v1.files.store' => 'uploads.single',
        ];
    }
}
