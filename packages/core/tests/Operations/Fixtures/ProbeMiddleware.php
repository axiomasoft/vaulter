<?php

declare(strict_types=1);

namespace Vaulter\Tests\Operations\Fixtures;

use Closure;
use RuntimeException;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;

/** An operation middleware whose behaviour a test picks: honest, or one way of breaking the contract. */
final class ProbeMiddleware implements OperationMiddleware
{
    public static string $mode = 'pass';

    /** @var list<string> */
    public static array $log = [];

    public function handle(Operation $operation, Closure $next): mixed
    {
        self::$log[] = 'probe:'.$operation->spec->name.($operation->isNested() ? ':nested' : '');

        return match (self::$mode) {
            'veto' => throw new RuntimeException('vetoed'),
            'skip' => 'made up',
            'twice' => [$next($operation), $next($operation)][1],
            'replace' => is_object($next($operation)) ? new \stdClass : 'replaced',
            'foreign' => $next(new Operation(spec: $operation->spec, context: $operation->context)),
            'swallow' => self::swallow($operation, $next),
            default => $next($operation),
        };
    }

    private static function swallow(Operation $operation, Closure $next): mixed
    {
        try {
            return $next($operation);
        } catch (RuntimeException) {
            return 'swallowed';
        }
    }
}
