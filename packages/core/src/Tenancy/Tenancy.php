<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Closure;
use Illuminate\Container\Container;
use Vaulter\Attributes\Api;

/**
 * The tenancy entry point of the kernel: runs maintenance code outside the tenant boundary. The scope is
 * fiber-local and nests; the previous state is restored when the callback returns or throws. Needs no drive: the
 * media blob commands and the drive jobs call it alike.
 */
#[Api]
final class Tenancy
{
    /**
     * @template T
     *
     * @param  Closure(): T $callback
     * @return T
     */
    public static function without(Closure $callback): mixed
    {
        return Container::getInstance()->make(TenancyBypass::class)->run($callback);
    }
}
