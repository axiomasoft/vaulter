<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use Closure;
use RuntimeException;
use Vaulter\Operations\Operation;
use Vaulter\Operations\OperationMiddleware;

/** Wraps every operation: honest by default, or one scripted way of breaking the contract. */
final class AcmeMiddleware implements OperationMiddleware
{
    /** pass | veto | skip | twice | replace */
    public static string $mode = 'pass';

    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function handle(Operation $operation, Closure $next): mixed
    {
        AcmeProbe::$log[] = 'middleware:'.$operation->spec->name;

        return match (self::$mode) {
            'veto' => throw new RuntimeException('acme vetoed'),
            'skip' => 'made up',
            'twice' => [$next($operation), $next($operation)][1],
            'replace' => is_object($next($operation)) ? new \stdClass : 'replaced',
            default => $next($operation),
        };
    }
}
