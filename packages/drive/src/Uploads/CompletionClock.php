<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\DriveOptions;

/** Starts completion deadlines from the typed config on a monotonic clock (a fake one in tests). */
#[Internal]
final readonly class CompletionClock
{
    /** @param (Closure(): float)|null $now monotonic seconds */
    public function __construct(
        private DriveOptions $config,
        private ?Closure $now = null,
    ) {}

    public function start(): CompletionDeadline
    {
        return CompletionDeadline::start(seconds: $this->config->uploads->resumable->completeTimeoutSeconds, clock: $this->now);
    }
}
