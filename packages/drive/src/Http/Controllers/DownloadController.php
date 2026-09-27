<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Support\DownloadHeaders;
use Vaulter\Http\Support\TrashGuard;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\FileVersionReadRepository;
use Vaulter\Support\LoadTier;

/**
 * Stream a file node's bytes (master plan §2.7 download). The version to serve is
 * the node's current head, or a specific historical version when one is named in
 * the route. The bytes ALWAYS pass through PHP (P1.2 / F-P1.3-01, D15): even on a
 * disk that can mint a signed URL (S3-style) the object is streamed straight off
 * the StorageDriver in chunks, never redirected to a presigned URL — a presigned
 * URL is a bearer token that is never re-authorized per request and cannot be
 * revoked instantly, which D15 rules out as a supported mode.
 *
 * Thin — the node/storage arrive route-model resolved; the version + blob are
 * resolved through FileVersionReadRepository (bounded to the node's own file), so
 * a version id from another file can never be streamed through this node.
 */
final class DownloadController extends Controller
{
    public function __construct(
        private readonly FileVersionReadRepository $versions,
        private readonly StorageDriver $driver,
        private readonly AccessResolver $access,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * Stream the node's current head version.
     */
    public function current(Request $request, Storage $storage, Node $node): StreamedResponse
    {
        $startedAt = hrtime(true);

        $this->guardDownload($request, $node);

        $version = $this->versions->head($node);

        return $this->stream($node, $version, $storage, source: 'file', startedAt: $startedAt);
    }

    /**
     * Stream a specific historical version of the node's file.
     */
    public function version(
        Request $request,
        Storage $storage,
        Node $node,
        int|string $version,
    ): StreamedResponse {
        $startedAt = hrtime(true);

        $this->guardDownload($request, $node);

        $resolved = $this->versions->forNode($node, $version);

        return $this->stream($node, $resolved, $storage, source: 'version', startedAt: $startedAt);
    }

    /**
     * Item-safety (P2.2 / F-P1.12-01): a trashed node 404s before authorization
     * even runs — trash is orthogonal to the ACL, not encoded into it. Then
     * authorize the Download ability (READ bit) through the AccessResolver
     * (H.2), 403ing on denial. An unauthenticated request is denied.
     */
    private function guardDownload(Request $request, Node $node): void
    {
        TrashGuard::assertNotTrashed($node);

        $user = $request->user();
        $actor = $user instanceof Authorizable ? $user : null;

        if ($actor === null || !$this->access->can($actor, Ability::Download, $node)) {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }
    }

    private function stream(
        Node $node,
        ?FileVersion $version,
        Storage $storage,
        string $source,
        int $startedAt,
    ): StreamedResponse {
        if (!$version instanceof FileVersion) {
            abort(404);
        }

        $blob = $version->blob()->first();

        if (!$blob instanceof Blob) {
            abort(404);
        }

        $filename = $version->original_name !== '' ? $version->original_name : $node->name;
        $contentType = $version->mime_type ?? $node->mime_type ?? 'application/octet-stream';

        return $this->streamFromDisk(
            blob: $blob,
            filename: $filename,
            contentType: $contentType,
            source: $source,
            startedAt: $startedAt,
        );
    }

    /**
     * Pipe the object off the StorageDriver to the client in chunks — the bytes
     * are never buffered whole in memory.
     */
    private function streamFromDisk(
        Blob $blob,
        string $filename,
        string $contentType,
        string $source,
        int $startedAt,
    ): StreamedResponse {
        return response()->streamDownload(
            function () use ($blob, $source, $startedAt): void {
                $handle = $this->driver->get(key: $blob->storage_key, disk: $blob->disk);

                if (!is_resource($handle)) {
                    abort(404);
                }

                $ttfbRecorded = false;
                $tier = LoadTier::label();

                while (!feof($handle)) {
                    $chunk = fread($handle, 1024 * 256);

                    if ($chunk === false) {
                        break;
                    }

                    $this->metrics->counter(
                        name: 'vaulter_download_bytes_total',
                        amount: strlen($chunk),
                        labels: ['source' => $source],
                    );

                    echo $chunk;

                    if (!$ttfbRecorded) {
                        $this->metrics->histogram(
                            name: 'vaulter_download_ttfb_seconds',
                            value: (hrtime(true) - $startedAt) / 1_000_000_000,
                            labels: ['tier' => $tier],
                        );
                        $ttfbRecorded = true;
                    }
                }

                fclose($handle);
            },
            name: $filename,
            headers: [
                ...DownloadHeaders::forDeclaredMime($contentType),
                'Content-Length' => (string) $blob->size_bytes,
            ],
        );
    }
}
