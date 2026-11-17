<?php

declare(strict_types=1);

namespace Vaulter\Tests\Doctor;

use Closure;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;

/** A check whose result a test supplies. */
final class ClosureCheck implements DoctorCheck
{
    /** @param Closure(DoctorContext): list<DoctorFinding> $run */
    public function __construct(private readonly string $key, private readonly Closure $run) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return 'Fake '.$this->key;
    }

    public function run(DoctorContext $context): array
    {
        return ($this->run)($context);
    }
}
