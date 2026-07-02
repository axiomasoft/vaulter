<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\FileVersionReadRepository;

/**
 * Stream a file node's bytes (master plan §2.7 download). The version to serve is
 * the node's current head, or a specific historical version when one is named in
 * the route. The bytes never pass through PHP memory: on a disk that can mint a
 * signed URL (S3-style) the client is redirected to a short-lived temporaryUrl;
 * otherwise the object is streamed straight off the StorageDriver in chunks.
 *
 * Thin — the node/storage arrive route-model resolved; the version + blob are
 * resolved through FileVersionReadRepository (bounded to the node's own file), so
 * a version id from another file can never be streamed through this node.
 */
final class DownloadController extends Controller
{
    /**
     * How long a redirected signed URL stays valid — a few minutes is enough for
     * the browser to follow the 302 and start the transfer.
     */
    private const int SIGNED_URL_TTL_MINUTES = 5;

    public function __construct(
        private readonly FileVersionReadRepository $versions,
        private readonly StorageDriver $driver,
        private readonly AccessResolver $access,
    ) {}

    /**
     * Stream the node's current head version.
     */
    public function current(Request $request, Storage $storage, Node $node): StreamedResponse|RedirectResponse
    {
        $this->guardDownload($request, $node);

        $version = $this->versions->head($node);

        return $this->stream($node, $version, $storage);
    }

    /**
     * Stream a specific historical version of the node's file.
     */
    public function version(
        Request $request,
        Storage $storage,
        Node $node,
        int|string $version,
    ): StreamedResponse|RedirectResponse {
        $this->guardDownload($request, $node);

        $resolved = $this->versions->forNode($node, $version);

        return $this->stream($node, $resolved, $storage);
    }

    /**
     * Authorize the Download ability (READ bit) on the node through the
     * AccessResolver (H.2), 403ing on denial. An unauthenticated request is denied.
     */
    private function guardDownload(Request $request, Node $node): void
    {
        $user = $request->user();
        $actor = $user instanceof Authorizable ? $user : null;

        if ($actor === null || !$this->access->can($actor, Ability::Download, $node)) {
            throw PermissionDeniedException::forAbility(Ability::Download->value);
        }
    }

    private function stream(Node $node, ?FileVersion $version, Storage $storage): StreamedResponse|RedirectResponse
    {
        if (!$version instanceof FileVersion) {
            abort(404);
        }

        $blob = $version->blob()->first();

        if (!$blob instanceof Blob) {
            abort(404);
        }

        $filename = $version->original_name !== '' ? $version->original_name : $node->name;
        $contentType = $version->mime_type ?? $node->mime_type ?? 'application/octet-stream';

        $signedUrl = $this->driver->temporaryUrl(
            key: $blob->storage_key,
            expiresAt: now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            disk: $blob->disk,
        );

        if (is_string($signedUrl)) {
            return new RedirectResponse($signedUrl);
        }

        return $this->streamFromDisk(blob: $blob, filename: $filename, contentType: $contentType);
    }

    /**
     * Pipe the object off the StorageDriver to the client in chunks — the bytes
     * are never buffered whole in memory.
     */
    private function streamFromDisk(Blob $blob, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($blob): void {
                $handle = $this->driver->get(key: $blob->storage_key, disk: $blob->disk);

                if (!is_resource($handle)) {
                    abort(404);
                }

                while (!feof($handle)) {
                    $chunk = fread($handle, 1024 * 256);

                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }

                fclose($handle);
            },
            name: $filename,
            headers: [
                'Content-Type' => $contentType,
                'Content-Length' => (string) $blob->size_bytes,
            ],
        );
    }
}
