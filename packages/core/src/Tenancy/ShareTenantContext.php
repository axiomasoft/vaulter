<?php

declare(strict_types=1);

namespace Vaulter\Tenancy;

use Closure;
use Fiber;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Drive;
use WeakMap;

/** @internal Token-authorized, drive-limited issuer evaluation; not maintenance bypass. */
#[Internal]
final class ShareTenantContext
{
    /** @var WeakMap<object, Drive> */
    private WeakMap $drives;

    private object $main;

    public function __construct()
    {
        $this->drives = new WeakMap;
        $this->main = new \stdClass;
    }

    public function drive(): ?Drive
    {
        return $this->drives[$this->key()] ?? null;
    }

    /** @template T
     * @param  Closure(): T $callback
     * @return T
     */
    public function evaluate(Drive $drive, Closure $callback): mixed
    {
        $key = $this->key();
        $previous = $this->drives[$key] ?? null;
        $this->drives[$key] = $drive;
        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset($this->drives[$key]);
            } else {
                $this->drives[$key] = $previous;
            }
        }
    }

    private function key(): object
    {
        return Fiber::getCurrent() ?? $this->main;
    }
}
