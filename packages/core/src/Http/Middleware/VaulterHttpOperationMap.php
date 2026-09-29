<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

/**
 * §9.1 server-side `operation` labels for {@see RecordVaulterRequestMetrics}.
 */
final class VaulterHttpOperationMap
{
    /**
     * @return list<array{methods: list<string>, pattern: string, operation: string}>
     */
    private static function rules(): array
    {
        return [
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}', 'operation' => 'point_read'],
            ['methods' => ['GET'], 'pattern' => 'storages/{id}', 'operation' => 'point_read'],
            ['methods' => ['GET'], 'pattern' => 'storages/{id}/nodes', 'operation' => 'tree_list'],
            ['methods' => ['GET'], 'pattern' => 'storages/{id}/trash', 'operation' => 'tree_list'],
            ['methods' => ['GET'], 'pattern' => 'storages/{id}/search', 'operation' => 'search'],
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/folders', 'operation' => 'tree_mutate'],
            ['methods' => ['PATCH'], 'pattern' => 'nodes/{id}', 'operation' => 'tree_mutate'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/copy', 'operation' => 'tree_mutate'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/restore', 'operation' => 'tree_mutate'],
            ['methods' => ['DELETE'], 'pattern' => 'nodes/{id}', 'operation' => 'tree_mutate'],
            ['methods' => ['DELETE'], 'pattern' => 'nodes/{id}/force', 'operation' => 'tree_mutate'],
            ['methods' => ['POST'], 'pattern' => 'nodes/{id}/move', 'operation' => 'subtree_mutate'],
            ['methods' => ['GET'], 'pattern' => 'storages/{id}/activity', 'operation' => 'activity_feed'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/activity', 'operation' => 'activity_feed'],
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
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/resumable-uploads', 'operation' => 'upload_session'],
            ['methods' => ['GET', 'HEAD', 'DELETE', 'OPTIONS'], 'pattern' => 'resumable-uploads/{id}', 'operation' => 'upload_session'],
            ['methods' => ['PATCH'], 'pattern' => 'resumable-uploads/{id}', 'operation' => 'tus_patch'],
            ['methods' => ['POST'], 'pattern' => 'resumable-uploads/{id}/parts', 'operation' => 's3_putpart'],
            ['methods' => ['POST'], 'pattern' => 'resumable-uploads/{id}/complete', 'operation' => 's3_complete'],
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/uploads', 'operation' => 'single_shot'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'nodes/{id}/versions/{id}/download', 'operation' => 'download'],
            ['methods' => ['GET'], 'pattern' => 'share/{token}/download', 'operation' => 'download'],
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
