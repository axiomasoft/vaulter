<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Data\Drive\FileStream;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Support\DownloadHeaders;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\LoadTier;
use Vaulter\VaulterManager;

/** Streams the authorized revision without buffering its bytes in memory. */
final class DownloadController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function current(Request $request, Drive $storage, Node $node): StreamedResponse
    {
        return $this->download($request, $storage, $node, null, 'file');
    }

    public function version(Request $request, Drive $storage, Node $node, int|string $version): StreamedResponse
    {
        return $this->download($request, $storage, $node, $version, 'version');
    }

    private function download(Request $request, Drive $storage, Node $node, int|string|null $version, string $source): StreamedResponse
    {
        $startedAt = hrtime(true);
        $actor = $request->user();
        if (!$actor instanceof Authorizable) {
            throw MissingActorException::required();
        }
        if (!$storage->exists) {
            $storage = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);
        }
        $file = $this->vaulter->drive(ownerOrDrive: $storage)->actingAs($actor)->files()->read(file: $node, revision: $version);

        return $this->stream($file, $source, $startedAt);
    }

    private function stream(FileStream $file, string $source, int $startedAt): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($file, $source, $startedAt): void {
                $ttfbRecorded = false;
                $tier = LoadTier::label();
                try {
                    while (!feof($file->stream)) {
                        $chunk = fread($file->stream, 1024 * 256);
                        if ($chunk === false) {
                            break;
                        }
                        $this->metrics->counter(name: 'vaulter_download_bytes_total', amount: strlen($chunk), labels: ['source' => $source]);
                        echo $chunk;
                        if (!$ttfbRecorded) {
                            $this->metrics->histogram(name: 'vaulter_download_ttfb_seconds', value: (hrtime(true) - $startedAt) / 1_000_000_000, labels: ['tier' => $tier]);
                            $ttfbRecorded = true;
                        }
                    }
                } finally {
                    if (is_resource($file->stream)) {
                        fclose($file->stream);
                    }
                }
            },
            name: $file->filename,
            headers: [...DownloadHeaders::forDeclaredMime($file->mimeType), 'Content-Length' => (string) $file->size],
        );
    }
}
