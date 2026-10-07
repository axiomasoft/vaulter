<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Content\ContentDelivery;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Http\Resources\Sharing\SharedNodeResource;
use Vaulter\Http\Support\DownloadHeaders;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveReadRepository;
use Vaulter\Repositories\Drive\FileRevisionReadRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Sharing\ShareContext;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Support\LoadTier;

/**
 * The PUBLIC, token-resolved surface for bearer share-links (§2.7 / M2c) — the
 * SEPARATE sharing pass. Every action sits behind {@see ResolveShareToken} (no auth
 * middleware of its own); the middleware has already produced the ShareContext, so
 * authorization here is ONLY the share's own bitmask on its own node-set — the
 * identity ACL / AccessResolver is never consulted.
 *
 *  - show     → the target node's metadata within scope (READ bit).
 *  - download → stream the target file's current head within the READ/DOWNLOAD
 *    bits, intersected with item-safety (the blob must be committed). The use
 *    budget is consumed atomically, bounded by max_uses.
 *  - nodes    → list the in-scope children, ONLY when scope=subtree (a scope=item
 *    share exposes a single node, never a listing).
 *
 * Thin — bytes stream off the StorageDriver, the version/blob resolve through the
 * FileRevisionReadRepository bounded to the node's own file.
 */
final class PublicShareController extends Controller
{
    public function __construct(
        private readonly NodeReadRepository $nodes,
        private readonly FileRevisionReadRepository $versions,
        private readonly DriveReadRepository $storages,
        private readonly NodeShareStoreRepository $shares,
        private readonly MetricsRecorder $metrics,
        private readonly ShareResolver $resolver,
    ) {}

    /**
     * The shared node's metadata within scope. Requires the READ bit (View). For a
     * scope=subtree folder share, the immediate children are embedded so a holder
     * can browse one level without a second call.
     */
    public function show(Request $request): JsonResponse
    {
        $context = ResolveShareToken::context($request);

        $this->requireBit($context, Ability::View);

        $resource = SharedNodeResource::make($context->node);

        if ($context->scope() === GrantScope::Subtree && $context->node->kind === NodeKind::Folder) {
            $resource->withChildren($this->inScopeChildren($context, $context->node));
        }

        return $resource->response();
    }

    /**
     * List the in-scope children of the shared folder (scope=subtree only). A
     * scope=item share has no listing — it exposes exactly one node.
     */
    public function nodes(Request $request): JsonResponse
    {
        $context = ResolveShareToken::context($request);

        $this->requireBit($context, Ability::View);

        if ($context->scope() !== GrantScope::Subtree) {
            // An item-scope share never lists — the link is for a single node.
            throw new NotFoundHttpException('This share link does not expose a listing.');
        }

        return SharedNodeResource::collection($this->inScopeChildren($context, $context->node))->response();
    }

    /**
     * Stream the shared file's current head version within the READ/DOWNLOAD bits,
     * intersected with item-safety (the blob must be committed). Consumes one use
     * atomically (bounded by max_uses) BEFORE streaming, so an over-budget hit is
     * rejected.
     */
    public function download(Request $request): StreamedResponse
    {
        $startedAt = hrtime(true);

        $context = ResolveShareToken::context($request);

        $this->requireBit($context, Ability::Download);

        $node = $context->node;

        if ($node->kind !== NodeKind::File) {
            throw new NotFoundHttpException('This share link does not point at a downloadable file.');
        }

        $storage = $this->storages->find($node->drive_id);

        if (!$storage instanceof Drive) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        // Atomic, max_uses-bounded use consumption — an exhausted budget loses the
        // race here and is denied before a single byte is served.
        if (!$this->shares->consumeUse($context->share)) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        return $this->stream($context, $node, $this->versions->head($node), $storage, startedAt: $startedAt);
    }

    /**
     * Reject the request unless the share's bitmask satisfies the ability. This is
     * the ONLY authorization on the public path — purely the share's bits, never an
     * identity grant.
     */
    private function requireBit(ShareContext $context, Ability $ability): void
    {
        if (!$context->allows($ability)) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }
    }

    /**
     * The in-scope direct children of a folder reached through a subtree share. The
     * NodeReadRepository::children is already bounded to the storage tree and live
     * (non-trashed) nodes; every result is additionally a descendant of the shared
     * node, so the listing can never escape the share's node-set.
     *
     * @return Collection<int, Node>
     */
    private function inScopeChildren(ShareContext $context, Node $parent): Collection
    {
        $storage = $this->storages->find($parent->drive_id);

        if (!$storage instanceof Drive) {
            /** @var Collection<int, Node> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, Node> $children */
        $children = $this->nodes->children($storage, $parent)->get();

        // Defence-in-depth: keep only nodes the share's node-set actually contains
        // (it always does for direct children of an in-scope folder, but this
        // guarantees a misshaped tree can never leak a sibling).
        return $children->filter(
            static fn (Node $child): bool => $context->containsNode($child),
        )->values();
    }

    private function stream(ShareContext $context, Node $node, ?FileRevision $version, Drive $storage, int $startedAt): StreamedResponse
    {
        if (!$version instanceof FileRevision) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        $blob = $version->blob()->first();

        // Item-safety: the blob must be live/committed — a pending or tombstoned
        // (quarantine-equivalent) blob is never served publicly.
        if (!$blob instanceof Blob || $blob->status !== BlobStatus::Committed) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        $this->resolver->assertContentAvailable(node: $node, revision: $version);

        $filename = $version->original_name !== '' ? $version->original_name : $node->name;
        $contentType = $version->mime_type ?? $node->mime_type ?? 'application/octet-stream';

        return $this->streamFromDisk(
            context: $context,
            node: $node,
            revision: $version,
            blob: $blob,
            filename: $filename,
            contentType: $contentType,
            startedAt: $startedAt,
        );
    }

    private function streamFromDisk(ShareContext $context, Node $node, FileRevision $revision, Blob $blob, string $filename, string $contentType, int $startedAt): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($context, $node, $revision, $startedAt): void {
                $file = app(ContentDelivery::class)->streamForShare($context, $node, $revision);
                $handle = $file->stream;

                if (!is_resource($handle)) {
                    abort(404);
                }

                $ttfbRecorded = false;
                $tier = LoadTier::label();

                try {
                    while (!feof($handle)) {
                        $chunk = fread($handle, 1024 * 256);

                        if ($chunk === false) {
                            break;
                        }

                        $this->metrics->counter(
                            name: 'vaulter_download_bytes_total',
                            amount: strlen($chunk),
                            labels: ['source' => 'share'],
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

                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
            },
            name: $filename,
            headers: [
                ...DownloadHeaders::forDeclaredMime($contentType),
                'Content-Length' => (string) $blob->size_bytes,
            ],
        );
    }
}
