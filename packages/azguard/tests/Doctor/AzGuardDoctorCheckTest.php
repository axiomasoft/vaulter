<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\Severity as DoctorSeverity;
use Vaulter\AzGuard\Testing\FakeAzGuardGuard;
use Vaulter\Doctor\DoctorRunner;

function azguardFindings(bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['azguard'])->checks[0]->findings;
}

it('is ok without an explicit guard, the driver builds one per actor', function (): void {
    $finding = azguardFindings()[0];

    expect($finding->code)->toBe('azguard.dependency_ok')
        ->and($finding->severity)->toBe(DoctorSeverity::Info)
        ->and($finding->evidence['driver_selected'])->toBeTrue();
});

it('reports a configured guard that cannot be built as an error in both modes', function (): void {
    config()->set('vaulter-azguard.guard', FakeAzGuardGuard::class);
    $this->app->bind(FakeAzGuardGuard::class, static fn (): never => throw new RuntimeException('panel secret=hunter2'));

    foreach ([false, true] as $production) {
        $finding = azguardFindings($production)[0];

        expect($finding->code)->toBe('azguard.guard_unresolvable')
            ->and($finding->severity)->toBe(DoctorSeverity::Error)
            ->and(json_encode($finding))->not->toContain('hunter2');
    }
});

it('notes whether the azguard driver is the selected one', function (): void {
    config(['vaulter-drive.access.permission_driver' => 'database']);

    expect(azguardFindings()[0]->evidence['driver_selected'])->toBeFalse();
});
