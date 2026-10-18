<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vaulter\Collaboration\Drain\DrainState;
use Vaulter\Collaboration\Providers\CollaborationRegistrar;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;

/** @return list<DoctorFinding> */
function collaborationFindings(bool $production = false): array
{
    return app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['collaboration.hub'])->checks[0]->findings;
}

/** The suite's default cache store is `array`; the draining flag gets a shared one unless a test says otherwise. */
function configureHub(array $hub = [], ?string $drainStore = 'file'): void
{
    config([
        'vaulter-collaboration.hub' => [
            'url' => 'wss://hub.example.test', 'jwt_secret' => 'jwt-secret-value', 'webhook_secret' => 'webhook-secret-value', 'token_ttl' => 300,
            ...$hub,
        ],
        'vaulter-collaboration.drain.cache_store' => $drainStore,
    ]);
}

it('accepts complete collaboration settings and never prints a secret', function (): void {
    configureHub();

    $findings = collaborationFindings(production: true);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('collaboration.hub_ok')
        ->and($findings[0]->evidence)->toBe(['jwt_secret_set' => true, 'webhook_secret_set' => true, 'hub_url_set' => true, 'drain_cache_store' => 'file', 'drain_cache_driver' => 'file'])
        ->and(json_encode($findings))->not->toContain('jwt-secret-value')->not->toContain('webhook-secret-value');
});

it('refuses a draining flag in a cache store no other process sees', function (?string $store, string $driver): void {
    configureHub(drainStore: $store);
    config(['cache.stores.local-only' => ['driver' => 'null']]);

    $finding = collaborationFindings()[0];

    expect($finding->code)->toBe('collaboration.drain_cache_local')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence['drain_cache_driver'])->toBe($driver);
})->with([
    'the default store of the suite (array)' => [null, 'array'],
    'a null store' => ['local-only', 'null'],
]);

it('refuses a draining flag store that is not configured', function (): void {
    configureHub(drainStore: 'missing');

    expect(collaborationFindings()[0]->code)->toBe('collaboration.drain_cache_missing');
});

it('warns while the draining flag refuses the appends', function (): void {
    configureHub();
    app(DrainState::class)->begin();

    try {
        $finding = collaborationFindings()[0];
    } finally {
        app(DrainState::class)->release();
    }

    expect($finding->code)->toBe('collaboration.draining')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->hint)->toContain('vaulter:collaboration:drain --release');
});

it('reports missing and equal secrets as errors', function (): void {
    configureHub(['jwt_secret' => null]);
    expect(collaborationFindings()[0]->code)->toBe('collaboration.hub_secrets_missing');

    configureHub(['jwt_secret' => 'same', 'webhook_secret' => 'same']);
    $finding = collaborationFindings()[0];
    expect($finding->code)->toBe('collaboration.hub_secrets_equal')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and(json_encode($finding))->not->toContain('"same"');
});

it('requires wss:// in production only', function (): void {
    configureHub(['url' => 'ws://hub.example.test']);

    expect(collaborationFindings()[0]->code)->toBe('collaboration.hub_ok');

    $finding = collaborationFindings(production: true)[0];
    expect($finding->code)->toBe('collaboration.hub_url_insecure')->and($finding->severity)->toBe(DoctorSeverity::Error);

    configureHub(['url' => null]);
    expect(collaborationFindings()[0]->code)->toBe('collaboration.hub_url_missing');
});

it('refuses a room token lifetime above an hour', function (): void {
    configureHub(['token_ttl' => 3600]);

    expect(collaborationFindings()[0]->code)->toBe('collaboration.hub_token_ttl');
});

it('lists every collaboration problem, not only the first', function (): void {
    configureHub(['jwt_secret' => null, 'url' => null, 'token_ttl' => 0]);

    $codes = array_map(static fn (DoctorFinding $finding): string => $finding->code, collaborationFindings());

    expect($codes)->toBe(['collaboration.hub_secrets_missing', 'collaboration.hub_url_missing', 'collaboration.hub_token_ttl']);
});

it('repeats the collaboration configuration validation as one of the config validators', function (): void {
    configureHub(['jwt_secret' => 'same', 'webhook_secret' => 'same']);

    $finding = app(DoctorRunner::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), ['core.config.valid'])->checks[0]->findings[0];

    expect($finding->severity)->toBe(DoctorSeverity::Error)
        ->and($finding->evidence['validator'])->toBe(CollaborationRegistrar::class);
});
