<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Configuration\DownloadsOptions;
use Vaulter\Drive\Content\ContentDelivery;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\FileStream;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\NotAFileException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\FileRevisionReadRepository;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Http\Middleware\AssignCorrelationId;
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
#[Internal]
final readonly class ContentResponder
{
    public function __construct(
        private ContentDelivery $delivery,
        private DownloadsOptions $downloads,
        private NodeLocator $locator,
        private FileRevisionReadRepository $revisions,
        private MetricsRecorder $metrics,
        private OperationGuard $guard,
    ) {}

    /** @param 'attachment'|'inline' $disposition */
    public function file(Request $request, DriveHandle $drive, string $nodeId, ?string $revisionId, string $disposition = 'attachment'): Response
    {
        $startedAt = hrtime(true);
        if ($this->downloads->deliveryMode === 'direct_signed') {
            return $this->redirect($request, $drive, $nodeId, $revisionId, $disposition, $startedAt);
        }

        return $this->stream($drive->files()->read($nodeId, $revisionId), $disposition, $revisionId === null ? 'file' : 'version', $startedAt, $request);
    }

    /**
     * A rendered document export as a download: the exporter's own content type is safe
     * here because the file is an attachment that a sandbox policy and `nosniff` keep from
     * running in the API origin. The caller has already been authorized for Download by
     * the operation that rendered the bytes.
     *
     * @param bool $lossy the stored content could only be read in part, so the file is not the whole document
     */
    public function export(string $bytes, string $filename, string $mimeType, bool $lossy = false): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => DownloadHeaders::disposition('attachment', $filename),
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Accept-Ranges' => 'none',
            'Cache-Control' => 'private, no-store',
            ...($lossy ? ['X-Vaulter-Export-Lossy' => '1'] : []),
        ]);
    }

    public function share(ShareContext $context, Node $node, FileRevision $revision, ?Request $request = null): Response
    {
        $startedAt = hrtime(true);

        return $this->stream($this->delivery->streamForShare($context, $node, $revision), 'attachment', 'share', $startedAt, $request);
    }

    /** @param 'attachment'|'inline' $disposition */
    private function redirect(Request $request, DriveHandle $drive, string $nodeId, ?string $revisionId, string $disposition, int $startedAt): Response
    {
        $context = DriveContext::for(drive: $drive->model(), actor: $drive->actor(), correlationId: AssignCorrelationId::of($request) ?? (string) Str::ulid());
        $node = $this->locator->find($context, $nodeId);
        // Match strict egress: View and Download precede kind and revision lookup.
        $this->guard->authorize($context, Ability::Download, $node, OperationKind::ContentEgress);
        if ($node->kind !== NodeKind::File || $node->file()->first() === null) {
            throw new NotAFileException;
        }
        $revision = ($revisionId === null ? $this->revisions->head($node) : $this->revisions->forNode($node, $revisionId))
            ?? throw NodeNotFoundException::forId($nodeId);
        if (!in_array($revision->status, ['committed', 'superseded'], true)) {
            throw NodeNotFoundException::forId($nodeId);
        }
        if ($revision->blob()->first()?->encryption_key_id !== null) {
            return $this->stream($this->delivery->stream($context, $node, $revision), $disposition, $revisionId === null ? 'file' : 'version', $startedAt, $request);
        }
        $url = $this->delivery->directUrl($context, $node, $revision, $this->downloads->directTtlSeconds);

        return new RedirectResponse($url->url, 302, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @param 'attachment'|'inline' $disposition */
    private function stream(FileStream $file, string $disposition, string $source, int $startedAt, ?Request $request): Response
    {
        $metrics = $this->metrics;
        $headers = DownloadHeaders::forContent($file->mimeType, $file->filename, $disposition, $file->size);
        $status = 200;
        $length = $file->size;
        if ($file->supportsRanges) {
            $headers['Accept-Ranges'] = 'bytes';
            $range = $request?->headers->get('Range');
            if ($range !== null && !$request->headers->has('If-Range') && preg_match('/\Abytes=(\d*)-(\d*)\z/i', $range, $parts) === 1) {
                $start = $parts[1] === '' ? max(0, $file->size - (int) $parts[2]) : (int) $parts[1];
                $end = $parts[1] === '' || $parts[2] === '' ? $file->size - 1 : min((int) $parts[2], $file->size - 1);
                if ($start > $end) {
                    fclose($file->stream);

                    abort(416, headers: ['Accept-Ranges' => 'bytes', 'Content-Range' => 'bytes */'.$file->size]);
                }
                if (fseek($file->stream, $start) !== 0) {
                    fclose($file->stream);
                    throw new RuntimeException('Could not seek to the requested content range.');
                }
                $status = 206;
                $length = $end - $start + 1;
                $headers['Content-Length'] = (string) $length;
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$file->size}";
            }
        }

        return new StreamedResponse(
            static function () use ($file, $source, $startedAt, $metrics, $length): void {
                $ttfbRecorded = false;
                $tier = LoadTier::label();
                try {
                    $remaining = $length;
                    while ($remaining > 0 && !feof($file->stream)) {
                        $chunk = fread($file->stream, min(1024 * 256, $remaining));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $remaining -= strlen($chunk);
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
            $status,
            $headers,
        );
    }
}
