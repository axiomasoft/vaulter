<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Doctor;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

/**
 * One read-only diagnostic of `vaulter:doctor`. A module contributes its checks to the DoctorCheck slot
 * under their keys (ModuleBuilder::slot()); a host may still tag one `vaulter.doctor_checks`. A check
 * never writes, publishes or migrates.
 */
#[Spi(SpiKind::Implement)]
interface DoctorCheck
{
    /** Dotted, unique key such as `core.database.connection`; `--check` selects by its prefix. */
    public function key(): string;

    public function title(): string;

    /** @return list<DoctorFinding> at least one: a check that finds nothing to report says so with an ok finding */
    public function run(DoctorContext $context): array;
}
