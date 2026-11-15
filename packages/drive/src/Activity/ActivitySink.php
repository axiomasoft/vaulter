<?php

declare(strict_types=1);

namespace Vaulter\Drive\Activity;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventSink;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;

/**
 * The drive's activity feed as an event sink (`drive.activity`): one row per occurrence of a type whose
 * spec has `activity`, while `vaulter-drive.features.activity` is on.
 */
#[Internal]
final readonly class ActivitySink implements EventSink
{
    public function __construct(
        private DriveConfig $config,
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
