<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Doctor;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Kernel;

#[Internal]
final readonly class AzGuardDependencyCheck implements DoctorCheck
{
    public function __construct(private Container $container, private Repository $config) {}

    public function key(): string
    {
        return 'azguard.dependency';
    }

    public function title(): string
    {
        return 'AzGuard bridge';
    }

    public function run(DoctorContext $context): array
    {
        $selected = $this->config->get('vaulter-drive.access.permission_driver') === 'azguard';
        $evidence = ['driver_selected' => $selected];

        if (!class_exists(AssignmentScopeRef::class)) {
            return [DoctorFinding::error(code: 'azguard.package_missing', message: 'The AzGuard package is not installed.', hint: 'composer require axiomasoft/azguard', evidence: $evidence)];
        }

        try {
            // Without an explicit guard the driver builds one per actor; a configured guard class must build.
            $capabilities = $this->container->make(Kernel::class)->capabilities();
            if ($capabilities->has(AzGuardGuard::class)) {
                $capabilities->require(AzGuardGuard::class);
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'azguard.guard_unresolvable', message: 'The configured AzGuardGuard cannot be built.', hint: 'check vaulter-azguard.guard and its dependencies', evidence: $evidence + ['exception' => $exception::class])];
        }

        return [DoctorFinding::ok(code: 'azguard.dependency_ok', message: 'AzGuard is installed and the guard resolves.', evidence: $evidence)];
    }
}
