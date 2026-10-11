<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Doctor;

use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/**
 * One read-only diagnostic of `vaulter:doctor`. A package contributes checks by tagging
 * them `vaulter.doctor_checks`; a check never writes, publishes or migrates.
 */
interface DoctorCheck
{
    /** Dotted, unique key such as `core.database.connection`; `--check` selects by its prefix. */
    public function key(): string;

    public function title(): string;

    /** @return list<DoctorFinding> at least one: a check that finds nothing to report says so with an ok finding */
    public function run(DoctorContext $context): array;
}
