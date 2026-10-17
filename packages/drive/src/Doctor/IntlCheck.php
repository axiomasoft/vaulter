<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

#[Internal]
final readonly class IntlCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'drive.intl';
    }

    public function title(): string
    {
        return 'PHP intl extension';
    }

    public function run(DoctorContext $context): array
    {
        if (extension_loaded('intl')) {
            return [DoctorFinding::ok(code: 'drive.intl_ok', message: 'ext-intl is loaded: names are normalized to NFC.')];
        }

        return [DoctorFinding::warning(
            code: 'drive.intl_missing',
            message: 'ext-intl is missing: file names are not normalized to NFC, so visually equal names can differ.',
            hint: 'install the PHP intl extension',
        )];
    }
}
