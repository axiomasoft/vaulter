<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

use Closure;
use Vaulter\Attributes\Api;

/** Owning modules/host construct this immutable task allowlist before provider boot. */
#[Api]
final readonly class JobRegistry
{
    /** @var array<string, Closure(JobRequest, ?PublicationContext): object> */
    private array $factories;

    /** @param array<mixed> $factories */
    public function __construct(array $factories)
    {
        foreach ($factories as $task => $factory) {
            if (!is_string($task) || !preg_match('/^[a-z][a-z0-9.-]{0,63}:[a-z][a-z0-9.-]{0,63}$/D', $task) || !$factory instanceof Closure) {
                throw new \InvalidArgumentException('Invalid neutral task factory.');
            }
        }
        $this->factories = $factories;
    }

    public function make(PreparedJob $prepared): object
    {
        $factory = $this->factories[$prepared->request->task] ?? throw new \InvalidArgumentException('Unknown publication task.');

        return $factory($prepared->request, $prepared->context);
    }
}
