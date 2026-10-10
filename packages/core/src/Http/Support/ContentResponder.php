<?php

declare(strict_types=1);

namespace Vaulter\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Configuration\DownloadsConfig;
use Vaulter\Content\ContentDelivery;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\NotAFileException;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\FileRevisionReadRepository;
use Vaulter\Sharing\ShareContext;
use Vaulter\Support\LoadTier;

/**
 * The only place the versioned API hands out file bytes or a URL to them.
 *
 * Every call goes through ContentDelivery, which re-checks access, tenant, profile
 * and scan state for this request and pins the exact revision; a revision that is not
 * clean answers 423 before a single byte or URL leaves. In the default `strict` mode
 * the bytes are streamed through the application. In `direct_signed` mode the caller
 * is redirected to a short-lived signed URL of that exact revision: revoking access
 * afterwards does not cancel a URL already issued, which is the documented price of
 * that mode. A stream that has already started is not interrupted by a revocation
 * either; the next request is refused.
 *
 * Public share downloads always stream, so each one is checked against the link's
 * current state.
 */
final readonly class ContentResponder
{
    public function __construct(
        private ContentDelivery $delivery,
        private DownloadsConfig $downloads,
        private NodeLocator $locator,
        private FileRevisionReadRepository $revisions,
        private MetricsRecorder $metrics,
    ) {}

    /** @param 'attachment'|'inline' $disposition */
    public function file(Request $request, DriveHandle $drive, string $nodeId, ?string $revisionId, string $disposition = 'attachment'): Response
    {
        $startedAt = hrtime(true);
        if ($this->downloads->deliveryMode() === 'direct_signed') {
            return $this->redirect($request, $drive, $nodeId, $revisionId);
        }

        return $this->stream($drive->files()->read($nodeId, $revisionId), $disposition, $revisionId === null ? 'file' : 'version', $startedAt);
    }

    public function share(ShareContext $context, Node $node, FileRevision $revision): StreamedResponse
    {
        $startedAt = hrtime(true);

        return $this->stream($this->delivery->streamForShare($context, $node, $revision), 'attachment', 'share', $startedAt);
    }

    private function redirect(Request $request, DriveHandle $drive, string $nodeId, ?string $revisionId): RedirectResponse
    {
        // View first, so a caller who cannot see the node learns nothing about its kind.
        if ($drive->nodes()->find($nodeId)->kind !== NodeKind::File) {
            throw new NotAFileException;
        }
        $context = new OperationContext(drive: $drive->model(), actor: $drive->actor(), correlationId: AssignCorrelationId::of($request) ?? (string) Str::ulid());
        $node = $this->locator->find($context, $nodeId);
        $revision = ($revisionId === null ? $this->revisions->head($node) : $this->revisions->forNode($node, $revisionId))
            ?? throw NodeNotFoundException::forId($nodeId);
        $url = $this->delivery->directUrl($context, $node, $revision, $this->downloads->directTtlSeconds());

        return new RedirectResponse($url->url, 302, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @param 'attachment'|'inline' $disposition */
    private function stream(FileStream $file, string $disposition, string $source, int $startedAt): StreamedResponse
    {
        $metrics = $this->metrics;

        return new StreamedResponse(
            static function () use ($file, $source, $startedAt, $metrics): void {
                $ttfbRecorded = false;
                $tier = LoadTier::label();
                try {
                    while (!feof($file->stream)) {
                        $chunk = fread($file->stream, 1024 * 256);
                        if ($chunk === false) {
                            break;
                        }
                        $metrics->counter(name: 'vaulter_download_bytes_total', amount: strlen($chunk), labels: ['source' => $source]);
                        echo $chunk;
                        if (!$ttfbRecorded) {
                            $metrics->histogram(name: 'vaulter_download_ttfb_seconds', value: (hrtime(true) - $startedAt) / 1_000_000_000, labels: ['tier' => $tier]);
                            $ttfbRecorded = true;
                        }
                    }
                } finally {
                    if (is_resource($file->stream)) {
                        fclose($file->stream);
                    }
                }
            },
            200,
            DownloadHeaders::forContent($file->mimeType, $file->filename, $disposition, $file->size),
        );
    }
}
