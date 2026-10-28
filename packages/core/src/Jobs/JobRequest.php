<?php

declare(strict_types=1);

namespace Vaulter\Jobs;

use Vaulter\Attributes\Api;

#[Api]
final readonly class JobRequest
{
    /** @param array<string,string> $refs */
    public function __construct(public string $task, public array $refs, public ?string $queue = null)
    {
        if (!preg_match('/^[a-z][a-z0-9.-]{0,63}:[a-z][a-z0-9.-]{0,63}$/D', $task)
            || ($queue !== null && !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $queue))) {
            throw new \InvalidArgumentException('Invalid publication task or queue.');
        }
        ScalarFields::validate($refs);
    }
}
