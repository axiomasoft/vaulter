<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

/**
 * §9.1 server-side `operation` labels for {@see RecordVaulterRequestMetrics}.
 *
 * Versioned routes are labelled by route name. The path rules serve the unversioned
 * documents routes until that package moves to v1, and URI-only collectors for the
 * byte-heavy v1 drive routes.
 */
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
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/content', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/document-versions', 'operation' => 'doc_read'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/handler', 'operation' => 'doc_read'],
            ['methods' => ['PATCH'], 'pattern' => 'nodes/{id}/content', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/documents', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/document-versions', 'operation' => 'doc_write'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/document-versions/{id}/restore', 'operation' => 'doc_write'],
            ['methods' => ['PATCH'], 'pattern' => 'nodes/{id}/document-versions/{id}/keep-forever', 'operation' => 'doc_write'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/export', 'operation' => 'doc_export'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/collab/token', 'operation' => 'collab_issue'],
            ['methods' => ['POST'], 'pattern' => 'collab/store', 'operation' => 'collab_persist'],
            ['methods' => ['GET', 'POST', 'DELETE'], 'pattern' => 'nodes/{id}/comments', 'operation' => 'comment'],
            ['methods' => ['GET', 'POST', 'DELETE'], 'pattern' => 'nodes/{id}/comments/{id}', 'operation' => 'comment'],
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
