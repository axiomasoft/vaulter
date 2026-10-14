<?php

declare(strict_types=1);

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Providers\BindingsRegistrar;

it('registers the kernel config, database and observability seams', function (): void {
    (new BindingsRegistrar)->register($this->app);

    expect(app(VaulterConfig::class))->toBe(app(VaulterConfig::class))
        ->and(app(VaulterDatabase::class))->toBe(app(VaulterDatabase::class))
        ->and(app(MetricsRecorder::class))->toBeInstanceOf(MetricsRecorder::class);
});
