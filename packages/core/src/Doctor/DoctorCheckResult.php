<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Packman\Attributes\Api;
use Packman\Doctor\Data\DoctorFinding;

#[Api]
final readonly class DoctorCheckResult
{
    /**
     * @param non-empty-list<DoctorFinding> $findings
     * @param string|null                   $detail   an exception message, kept out of the findings so the JSON document never carries it
     */
    public function __construct(public string $key, public string $title, public array $findings, public ?string $detail = null) {}
}
