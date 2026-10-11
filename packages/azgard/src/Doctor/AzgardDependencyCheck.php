<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Doctor;

use AzGuard\Kernel\Identity\AssignmentScopeRef;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class AzgardDependencyCheck implements DoctorCheck
{
    public function __construct(private Container $container, private Repository $config) {}

    public function key(): string
    {
        return 'azgard.dependency';
    }

    public function title(): string
    {
        return 'AzGuard bridge';
    }

    public function run(DoctorContext $context): array
    {
        $selected = $this->config->get('vaulter.access.permission_driver') === 'azgard';
        $evidence = ['driver_selected' => $selected];

        if (!class_exists(AssignmentScopeRef::class)) {
            return [DoctorFinding::error(code: 'azgard.package_missing', message: 'The AzGuard package is not installed.', hint: 'composer require axiomasoft/azguard', evidence: $evidence)];
        }

        try {
            // A console run has no user, so the bridge answers with its deny-all guard; what matters is that it builds.
            $this->container->make(AzgardGuard::class);
        } catch (Throwable $exception) {
            return [DoctorFinding::error(code: 'azgard.guard_unresolvable', message: 'The AzgardGuard binding cannot be built.', hint: 'check the auth guard and the AzGuard panel id', evidence: $evidence + ['exception' => $exception::class])];
        }

        return [DoctorFinding::ok(code: 'azgard.dependency_ok', message: 'AzGuard is installed and the guard resolves.', evidence: $evidence)];
    }
}
