<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\NullAzgardGuard;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;

function azgardFindings(bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['azgard'])->checks[0]->findings;
}

it('resolves the bridge guard for a console run without a user', function (): void {
    $finding = azgardFindings()[0];

    expect($finding->code)->toBe('azgard.dependency_ok')
        ->and($finding->severity)->toBe(DoctorSeverity::Ok)
        ->and($finding->evidence['driver_selected'])->toBeTrue()
        ->and(app(AzgardGuard::class))->toBeInstanceOf(NullAzgardGuard::class);
});

it('reports a guard that cannot be built as an error in both modes', function (): void {
    $this->app->bind(AzgardGuard::class, static fn (): never => throw new RuntimeException('panel secret=hunter2'));

    foreach ([false, true] as $production) {
        $finding = azgardFindings($production)[0];

        expect($finding->code)->toBe('azgard.guard_unresolvable')
            ->and($finding->severity)->toBe(DoctorSeverity::Error)
            ->and(json_encode($finding))->not->toContain('hunter2');
    }
});

it('notes whether the azgard driver is the selected one', function (): void {
    config(['vaulter.access.permission_driver' => 'database']);

    expect(azgardFindings()[0]->evidence['driver_selected'])->toBeFalse();
});
