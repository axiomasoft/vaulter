<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vaulter\Azgard\Testing\FakeAzgardGuard;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;

function azgardFindings(bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['azgard'])->checks[0]->findings;
}

it('is ok without an explicit guard, the driver builds one per actor', function (): void {
    $finding = azgardFindings()[0];

    expect($finding->code)->toBe('azgard.dependency_ok')
        ->and($finding->severity)->toBe(DoctorSeverity::Ok)
        ->and($finding->evidence['driver_selected'])->toBeTrue();
});

it('reports a configured guard that cannot be built as an error in both modes', function (): void {
    config()->set('vaulter-azgard.guard', FakeAzgardGuard::class);
    $this->app->bind(FakeAzgardGuard::class, static fn (): never => throw new RuntimeException('panel secret=hunter2'));

    foreach ([false, true] as $production) {
        $finding = azgardFindings($production)[0];

        expect($finding->code)->toBe('azgard.guard_unresolvable')
            ->and($finding->severity)->toBe(DoctorSeverity::Error)
            ->and(json_encode($finding))->not->toContain('hunter2');
    }
});

it('notes whether the azgard driver is the selected one', function (): void {
    config(['vaulter-drive.access.permission_driver' => 'database']);

    expect(azgardFindings()[0]->evidence['driver_selected'])->toBeFalse();
});
