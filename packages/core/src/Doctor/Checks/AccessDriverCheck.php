<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Throwable;
use Vaulter\Access\PermissionDriverCatalog;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Contracts\Access\NonProductionPermissionDriver;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

#[Internal]
final readonly class AccessDriverCheck implements DoctorCheck
{
    public function __construct(private AccessConfig $access, private PermissionDriverCatalog $drivers) {}

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
            $key = $this->access->permissionDriver();
            $class = $this->drivers->class($key);
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'core.access.invalid', message: 'The permission driver configuration is invalid.', evidence: ['exception' => $exception::class])];
        }

        $evidence = ['driver' => $key];
        if ($key === 'null') {
            return [DoctorFinding::error(code: 'core.access.null_driver', message: 'The "null" permission driver grants nothing or everything by design and is not a real access model.', hint: 'select a production driver such as database, gate or spatie', evidence: $evidence)];
        }
        if ($class === null || !class_exists($class) || !is_a($class, PermissionDriver::class, true)) {
            return [DoctorFinding::error(code: 'core.access.unresolved', message: 'The permission driver "'.$key.'" does not resolve to a PermissionDriver class.', hint: 'check vaulter.access.permission_driver and the host map vaulter.access.permission_drivers', evidence: $evidence)];
        }
        if ($context->production && is_a($class, NonProductionPermissionDriver::class, true)) {
            return [DoctorFinding::error(code: 'core.access.non_production_driver', message: 'The permission driver "'.$key.'" is for tests and development only.', hint: 'set VAULTER_PERMISSION_DRIVER to a production driver', evidence: $evidence)];
        }

        return [DoctorFinding::ok(code: 'core.access.driver_ok', message: 'The permission driver "'.$key.'" resolves.', evidence: $evidence)];
    }
}
