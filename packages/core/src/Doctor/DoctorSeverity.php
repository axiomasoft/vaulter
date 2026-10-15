<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

use Vaulter\Attributes\Api;

#[Api]
enum DoctorSeverity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
