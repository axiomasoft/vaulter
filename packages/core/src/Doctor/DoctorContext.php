<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use DateTimeImmutable;
use Vaulter\Attributes\Api;

#[Api]
final readonly class DoctorContext
{
    /**
     * @param bool              $production   the host is checked against its production profile: part of the warnings become errors
     * @param bool              $probeStorage the owner allowed the one write doctor makes, a test object on each storage disk
     * @param positive-int      $sampleLimit  row bound of the sampling checks
     * @param DateTimeImmutable $now          UTC; every check measures age against it
     */
    public function __construct(
        public bool $production,
        public bool $probeStorage,
        public int $sampleLimit,
        public DateTimeImmutable $now,
    ) {}

    /** Severity of a finding that is a warning on a development host and an error in production. */
    public function strictSeverity(): DoctorSeverity
    {
        return $this->production ? DoctorSeverity::Error : DoctorSeverity::Warning;
    }

    /** The production profile probes the disks without being asked twice. */
    public function mayProbeStorage(): bool
    {
        return $this->production || $this->probeStorage;
    }
}
