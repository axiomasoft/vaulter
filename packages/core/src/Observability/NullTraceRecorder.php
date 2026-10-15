<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\TraceRecorder;

#[Internal]
final class NullTraceRecorder implements TraceRecorder
{
    /** @param array<string, scalar> $attributes */
    public function span(string $name, callable $operation, array $attributes = []): mixed
    {
        return $operation();
    }
}
