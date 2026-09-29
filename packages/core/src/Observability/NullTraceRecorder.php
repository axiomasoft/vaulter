<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Vaulter\Contracts\Observability\TraceRecorder;

final class NullTraceRecorder implements TraceRecorder
{
    /** @param array<string, scalar> $attributes */
    public function span(string $name, callable $operation, array $attributes = []): mixed
    {
        return $operation();
    }
}
