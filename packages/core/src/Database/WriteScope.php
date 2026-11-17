<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;

/**
 * Tracks whether the current unit of work is a Vaulter operation. Every write to a
 * Vaulter model asks it first; outside VaulterDatabase::transaction() and
 * VaulterDatabase::write() the write is refused (defence in depth: a host
 * cannot bypass the invariants and the authorization of the Operations by saving a
 * model it got hold of). Bound as a scoped service: one counter per request, job or
 * Octane tick. It is not a sandbox; DB::table() and raw SQL are not intercepted.
 */
#[Internal]
final class WriteScope
{
    private int $depth = 0;

    public function __construct(private readonly DatabaseOptions $options) {}

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        $this->enter();
        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }

    /** Open the scope until the matching {@see self::leave()}; tests that drive internals directly hold it open. */
    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /** @throws WriteOutsideOperationException */
    public function assertOpen(string $target): void
    {
        if ($this->depth === 0 && $this->options->writeGuard) {
            throw new WriteOutsideOperationException($target, $this->caller());
        }
    }

    /** The first frame outside the framework and the guard itself: where the write was issued. */
    private function caller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) || str_starts_with($file, __DIR__) || str_contains($file, DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return $file.':'.($frame['line'] ?? 0);
        }

        return null;
    }
}
