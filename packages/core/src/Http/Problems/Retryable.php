<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Packman\Attributes\Api;

/**
 * Whether a client may repeat a request that failed with a problem code (architecture#errors): never as it
 * is, after it refetched the resource (a stale precondition), or later with backoff (a busy or unavailable
 * dependency, honouring `Retry-After` when the response carries one).
 */
#[Api]
enum Retryable: string
{
    case Never = 'never';
    case AfterRefetch = 'after_refetch';
    case WithBackoff = 'with_backoff';
}
