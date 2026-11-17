<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Closure;
use Fiber;
use Packman\Attributes\Internal;
use stdClass;
use WeakMap;

/** Scoped, fiber-local maintenance state; never serialized into jobs. */
#[Internal]
final class TenancyBypass
{
    /** @var WeakMap<object, int> */
    private WeakMap $depth;

    private object $main;

    public function __construct()
    {
        $this->depth = new WeakMap;
        $this->main = new stdClass;
    }

    public function active(): bool
    {
        return ($this->depth[$this->key()] ?? 0) > 0;
    }

    /** @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return $this->withDepth(($this->depth[$this->key()] ?? 0) + 1, $callback);
    }

    /** @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function suspend(Closure $callback): mixed
    {
        return $this->withDepth(0, $callback);
    }

    /** @template T
     * @param  Closure(): T $callback
     * @return T
     */
    private function withDepth(int $depth, Closure $callback): mixed
    {
        $key = $this->key();
        $before = $this->depth[$key] ?? 0;
        $this->depth[$key] = $depth;
        try {
            return $callback();
        } finally {
            $this->depth[$key] = $before;
        }
    }

    private function key(): object
    {
        return Fiber::getCurrent() ?? $this->main;
    }
}
