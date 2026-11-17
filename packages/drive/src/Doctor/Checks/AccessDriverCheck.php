<?php

declare(strict_types=1);

namespace Vaulter\Drive\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;
use Vaulter\Drive\Access\PermissionDriverCatalog;
use Vaulter\Drive\Configuration\AccessOptions;
use Vaulter\Drive\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Drive\Contracts\Access\PermissionDriver;

#[Internal]
final readonly class AccessDriverCheck implements DoctorCheck
{
    public function __construct(private AccessOptions $access, private PermissionDriverCatalog $drivers) {}

    public function key(): string
    {
        return 'core.access.driver';
    }

    public function title(): string
    {
        return 'Permission driver';
    }

    public function run(DoctorContext $context): array
    {
        try {
            $key = $this->access->permissionDriver;
            $class = $this->drivers->class($key);
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'core.access.invalid',
                message: 'The permission driver configuration is invalid.',
                remediation: 'check vaulter-drive.access.permission_driver and vaulter-drive.access.permission_drivers',
                evidence: ['exception' => $exception::class],
            )];
        }

        $evidence = ['driver' => $key];
        if ($key === 'null') {
            return [DoctorFinding::error(
                code: 'core.access.null_driver',
                message: 'The "null" permission driver grants nothing or everything by design and is not a real access model.',
                remediation: 'select a production driver such as database, gate or spatie',
                evidence: $evidence,
            )];
        }
        if ($class === null || !class_exists($class) || !is_a($class, PermissionDriver::class, true)) {
            return [DoctorFinding::error(
                code: 'core.access.unresolved',
                message: 'The permission driver "'.$key.'" does not resolve to a PermissionDriver class.',
                remediation: 'check vaulter-drive.access.permission_driver and the host map vaulter-drive.access.permission_drivers',
                evidence: $evidence,
            )];
        }
        if ($context->production && is_a($class, NonProductionPermissionDriver::class, true)) {
            return [DoctorFinding::error(
                code: 'core.access.non_production_driver',
                message: 'The permission driver "'.$key.'" is for tests and development only.',
                remediation: 'set VAULTER_PERMISSION_DRIVER to a production driver',
                evidence: $evidence,
            )];
        }

        return [DoctorFinding::ok(code: 'core.access.driver_ok', message: 'The permission driver "'.$key.'" resolves.', evidence: $evidence)];
    }
}
