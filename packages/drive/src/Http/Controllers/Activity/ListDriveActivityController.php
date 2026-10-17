<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Activity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Drive\Http\Resources\Activity\ActivityResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Support\LoadTier;

/** The drive's activity the caller may see (View on the root), newest first. */
#[Internal]
final readonly class ListDriveActivityController
{
    public function __construct(private HttpOperationContextFactory $contexts, private MetricsRecorder $metrics, private VaulterConfig $config) {}

    public function __invoke(Request $request, string $drive): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $nodeType = $this->nodeType();

        return $this->metrics->timing(
            name: 'vaulter_activity_feed_duration_seconds',
            operation: fn (): JsonResponse => $this->contexts->onDrive($request, $drive, static fn (DriveHandle $handle): JsonResponse => PageResponse::make(
                $handle->activity()->forDrive($options),
                static fn (ActivityEntrySnapshot $entry): ActivityResource => new ActivityResource($entry, $nodeType),
                $options->perPage,
            )),
            labels: ['filtered' => false, 'tier' => LoadTier::label()],
        );
    }

    /** The morph type activity rows record for a node target. */
    private function nodeType(): string
    {
        $class = $this->config->model('node');

        return (new $class)->getMorphClass();
    }
}
