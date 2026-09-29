<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Observability;

use Vaulter\Observability\NullTraceRecorder;

/**
 * SDK-neutral tracing seam (VLT-LOAD P8.9 / §20). Package code depends only on this
 * contract; the host binds a real adapter. With no backend installed the container
 * resolves {@see NullTraceRecorder}.
 */
interface TraceRecorder
{
    /**
     * @template T
     *
     * @param  callable(): T         $operation
     * @param  array<string, scalar> $attributes
     * @return T
     */
    public function span(string $name, callable $operation, array $attributes = []): mixed;
}
