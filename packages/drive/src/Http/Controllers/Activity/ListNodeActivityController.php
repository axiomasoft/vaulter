<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Activity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Drive\Data\Snapshots\ActivityEntrySnapshot;
use Vaulter\Http\Resources\Activity\ActivityResource;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;
use Vaulter\Support\LoadTier;

/** A node's activity the caller may see (View on the node), newest first. */
#[Internal]
final readonly class ListNodeActivityController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private MetricsRecorder $metrics, private VaulterConfig $config) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $options = PageResponse::listOptions($request);
        $handle = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $nodeType = $this->nodeType();

        return $this->metrics->timing(
            name: 'vaulter_activity_feed_duration_seconds',
            operation: static fn (): JsonResponse => PageResponse::make(
                $handle->activity()->forNode($node, $options),
                static fn (ActivityEntrySnapshot $entry): ActivityResource => new ActivityResource($entry, $nodeType),
                $options->perPage,
            ),
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
