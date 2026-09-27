<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Load\Observability\LoadExpositionRecorder;
use Vaulter\Load\Observability\RedisMetricsRegistry;

it('records into the active run shared by long-lived load processes', function (): void {
    $registry = app(RedisMetricsRegistry::class);
    $runId = (string) Str::uuid();
    $registry->openRun($runId, 120);

    putenv('LOAD_RUN_ID=00000000-0000-4000-8000-000000000000');
    $_ENV['LOAD_RUN_ID'] = '00000000-0000-4000-8000-000000000000';

    (new LoadExpositionRecorder($registry))->counter('vaulter_outbox_relayed_total');

    expect($registry->exposition($runId))->toContain("vaulter_outbox_relayed_total 1\n");
});
