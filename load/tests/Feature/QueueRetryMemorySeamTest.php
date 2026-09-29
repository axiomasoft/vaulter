<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Testing\FakeMetricsRecorder;

uses(RefreshDatabase::class);

it('runs retry through the product command and emits its memory gauge', function (): void {
    expect(config('database.connections.testing.database'))->toBe(':memory:');
    $metrics = new FakeMetricsRecorder;
    app()->instance(MetricsRecorder::class, $metrics);
    // Laravel skips console event routing in unit tests; enable the runtime path.
    app(Kernel::class)->rerouteSymfonyCommandEvents();

    expect(Artisan::call('load:queue-probe', ['action' => 'retry', '--run-id' => 'r09-memory-test']))->toBe(0);
    $samples = array_values(array_filter($metrics->emissions(), static fn (array $sample): bool => $sample['name'] === 'vaulter_command_peak_memory_bytes'));
    expect($samples)->toHaveCount(1)
        ->and($samples[0]['labels'])->toBe(['command' => 'vaulter:retry-webhooks'])
        ->and($samples[0]['value'])->toBeGreaterThan(0);
});
