<?php

declare(strict_types=1);

// P2.2 C5 repro — REAL host-publish simulation, self-contained (writes/removes the
// fixture config file itself so this repro is reproducible standalone via
// `/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P2.2`).
//
// A vaulter.php config file is placed on disk at the Testbench skeleton's
// config_path() BEFORE the application boots, so Laravel's real LoadConfiguration
// bootstrapper (which ALWAYS runs before any ServiceProvider::register() — confirmed
// by CreatesApplication::resolveApplicationConfiguration() at line ~413 running
// BEFORE RegisterProviders at line ~534, see TraceOrderTest.php) loads it into
// config('vaulter') exactly like it would for a real host that ran
// `php artisan vendor:publish --tag=vaulter-config` and edited one key.
//
// (An earlier attempt using Testbench's defineEnvironment() hook to inject the
// override was a FALSE simulation: defineEnvironment() runs at line ~543, AFTER
// RegisterProviders at line ~534 — i.e. AFTER all package providers already merged
// their config. That ordering quirk is Testbench-specific test-hook machinery, not
// representative of a real Laravel boot, and produced a misleading "keys vanish"
// result that was an artifact of the harness, not the package — see
// BaselineNoOverrideTest.php / TraceOrderTest.php for the corroborating traces.)

use Vaulter\Tests\P22Repro\RealPublishedConfigBase;

require __DIR__.'/RealPublishedConfigBase.php';

uses(RealPublishedConfigBase::class);

// The fixture config file is written/removed in RealPublishedConfigBase's
// setUpBeforeClass()/tearDownAfterClass() — those PHPUnit static hooks run BEFORE
// this class's per-test setUp() (which is where Testbench boots the application and
// runs LoadConfiguration), and are scoped to THIS class only, so they cannot leak
// into BaselineNoOverrideTest.php / TraceOrderTest.php in the same directory run.
// (beforeEach()/afterEach() were tried first and fire too late — from inside
// setUp() itself, AFTER parent::setUp() already booted the app; see the class
// docblock for the full explanation.)

it('preserves host override + adds drive/documents keys when config/vaulter.php is REALLY published to disk before boot', function () {
    $models = $this->app['config']->get('vaulter.models');

    dump('RealPublishedConfig vaulter.models = '.json_encode($models, JSON_PRETTY_PRINT));

    expect($models['node_comment'])->toBe('App\\Models\\HostNodeComment')
        ->and($models['storage'])->not->toBeEmpty()
        ->and($models)->toHaveKey('upload_session')
        ->and($models)->toHaveKey('upload_part')
        ->and($models)->toHaveKey('node_document_version');
});
