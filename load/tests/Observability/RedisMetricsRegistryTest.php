<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Vaulter\Load\Observability\MetricFamilyCatalog;
use Vaulter\Load\Observability\RedisMetricsRegistry;

beforeEach(function (): void {
    if (!extension_loaded('redis')) {
        $this->markTestSkipped('phpredis extension required');
    }

    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis not reachable');
    }
});

it('registers fifty families and preserves histogram +Inf == _count invariant', function (): void {
    $registry = app(RedisMetricsRegistry::class);
    $runId = 'pest-'.uniqid('', true);

    $registry->openRun($runId, 120);

    expect(Redis::connection()->scard("vaulter:metrics:{{$runId}}:manifest"))->toBe(50);

    $registry->observeHistogram(
        runId: $runId,
        name: 'vaulter_outbox_stage_duration_seconds',
        value: 0.02,
        labels: [],
        bucketSet: 'stage_s',
    );

    $key = "vaulter:metrics:{{$runId}}:series:vaulter_outbox_stage_duration_seconds:";
    $count = (int) Redis::connection()->hget($key, '_count');
    $inf = (int) Redis::connection()->hget($key, 'le:+Inf');

    expect($inf)->toBe($count);

    $body = $registry->renderExposition($runId);

    foreach (array_keys(MetricFamilyCatalog::families()) as $family) {
        expect($body)->toContain("# TYPE {$family}");
    }
});
