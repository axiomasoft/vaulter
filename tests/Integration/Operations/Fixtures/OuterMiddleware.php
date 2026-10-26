<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Operations\Fixtures;

use Closure;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;

/** An honest middleware that records the order it runs in. */
final class OuterMiddleware implements OperationMiddleware
{
    public function handle(Operation $operation, Closure $next): mixed
    {
        ProbeMiddleware::$log[] = 'outer:before';
        $result = $next($operation);
        ProbeMiddleware::$log[] = 'outer:after';

        return $result;
    }
}
