<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Vaulter\Attributes\Internal;

/**
 * server-side `operation` labels for {@see RecordVaulterRequestMetrics}.
 *
 * Routes are labelled by name. The path rules exist for collectors that see only a URI
 * (php-fpm status, the load stand) and cover the versioned routes whose cost differs
 * most: bytes in or out, document reads and writes, the hub.
 */
#[Internal]
final class VaulterHttpOperationMap
{
    /** @var array<string, string> route name => operation label */
    private const array NAMED = [
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

    public static function operationFor(string $routeName): ?string
    {
        return self::NAMED[$routeName] ?? null;
    }

    /**
     * @return list<array{methods: list<string>, pattern: string, operation: string}>
     */
    private static function rules(): array
    {
        return [
            // Collectors that see only a URI (php-fpm status, the load stand) label the
            // byte-heavy drive routes by path.
            ['methods' => ['GET'], 'pattern' => 'v1/files/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'v1/files/{id}/revisions/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'v1/shares/{id}/download', 'operation' => 'download'],
            ['methods' => ['POST'], 'pattern' => 'v1/drives/{id}/files', 'operation' => 'single_shot'],
            ['methods' => ['POST'], 'pattern' => 'v1/files/{id}/revisions', 'operation' => 'single_shot'],
            ['methods' => ['PATCH'], 'pattern' => 'v1/uploads/{id}', 'operation' => 'tus_patch'],
            ['methods' => ['POST'], 'pattern' => 'v1/uploads/{id}/parts', 'operation' => 's3_putpart'],
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

    public static function resolve(string $method, string $pathAfterPrefix): ?string
    {
        $method = strtoupper($method);
        $normalized = trim($pathAfterPrefix, '/');

        foreach (self::rules() as $rule) {
            if (!in_array($method, $rule['methods'], true)) {
                continue;
            }

            $regex = '#^'.preg_replace('#\{id\}#', '[^/]+', $rule['pattern']).'$#';

            if (preg_match($regex, $normalized) === 1) {
                return $rule['operation'];
            }
        }

        return null;
    }
}
