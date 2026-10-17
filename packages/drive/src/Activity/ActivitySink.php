<?php

declare(strict_types=1);

namespace Vaulter\Drive\Activity;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * The drive's activity feed as an event sink (`drive.activity`, D38): one row per occurrence of a type whose
 * spec has `activity`, while `vaulter.features.activity` is on. Located in core until P4 moves the activity
 * slice to the drive package.
 */
#[Internal]
final readonly class ActivitySink implements EventSink
{
    public function __construct(
        private VaulterConfig $config,
        private Container $container,
    ) {}

    public function record(DomainEvent $event, SinkWriter $writer): SinkResult
    {
        if (!$this->config->featureEnabled('activity') || !$event->type()->spec()->activity) {
            return SinkResult::Skipped;
        }

        // Resolved per event: the recorder is swappable in tests.
        return $this->container->make(ActivityRecorder::class)->record($event) ? SinkResult::Stored : SinkResult::Replayed;
    }
}
