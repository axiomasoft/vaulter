<?php

declare(strict_types=1);

namespace Vaulter\Doctor;

enum DoctorSeverity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
