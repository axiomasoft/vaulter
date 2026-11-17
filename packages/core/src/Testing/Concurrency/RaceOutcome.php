<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concurrency;

use Packman\Attributes\Internal;

/**
 * The outcome of one ForkRace task, read back by the parent process.
 *
 * `$exception` is the FQCN of the Throwable the task raised, or a `ForkRace\…`
 * marker for a harness-level failure (barrier timeout, process timeout, a value
 * that cannot cross the process boundary as JSON, a child that wrote no result).
 */
#[Internal]
final readonly class RaceOutcome
{
    public function __construct(
        public bool $ok,
        public mixed $value,
        public ?string $exception,
        public ?string $message,
    ) {}

    public function failedWith(string $class): bool
    {
        return !$this->ok
            && $this->exception !== null
            && is_a($this->exception, $class, true);
    }
}
