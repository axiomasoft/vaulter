<?php

declare(strict_types=1);

// P2.2 C3/C13 repro — see ConfigCacheSmokeBase.php for methodology + the CLI-vs-Pest
// deviation rationale, and tearDownAfterClass() for why cleanup does NOT go through
// Artisan (a broken post-cache boot would make config:clear fail too).

use Illuminate\Support\Facades\Artisan;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Support\Config;
use Vaulter\Tests\P22Repro\ConfigCacheSmokeBase;
use Vaulter\VaulterManager;

require __DIR__.'/ConfigCacheSmokeBase.php';

uses(ConfigCacheSmokeBase::class);

it('C3: Config::featureEnabled() reads a real config:cache run WITHOUT re-merge, matching pre-cache values', function () {
    $before = [
        'reliability' => Config::featureEnabled('reliability'),
        'activity' => Config::featureEnabled('activity'),
        'comments' => Config::featureEnabled('comments'),
        'models' => array_keys((array) config('vaulter.models')),
    ];

    dump('BEFORE config:cache = '.json_encode($before));

    $exitCode = Artisan::call('config:cache');

    dump('config:cache exit='.$exitCode.' output='.trim(Artisan::output()));

    expect($exitCode)->toBe(0);

    // featureEnabled() / config('vaulter.models') read the LIVE config repository —
    // config:cache does not clear or reload it mid-request, it only dumps the
    // CURRENT array to disk. Confirms C3's "no runtime re-merge dependency" for the
    // read side within the SAME booted request.
    $after = [
        'reliability' => Config::featureEnabled('reliability'),
        'activity' => Config::featureEnabled('activity'),
        'comments' => Config::featureEnabled('comments'),
        'models' => array_keys((array) config('vaulter.models')),
    ];

    dump('AFTER config:cache (same process) = '.json_encode($after));

    expect($after)->toBe($before);
});

it('C13: a FRESH reboot under the dumped cache — what actually got persisted', function () {
    Artisan::call('config:cache');

    $cachePath = dirname(__DIR__, 5).'/vendor/orchestra/testbench-core/laravel/bootstrap/cache/config.php';
    $cached = require $cachePath;

    $vaulterProviders = array_values(array_filter(
        $cached['app']['providers'] ?? [],
        static fn ($p) => str_contains((string) $p, 'Vaulter'),
    ));

    dump('cached app.providers Vaulter entries = '.json_encode($vaulterProviders));
    dump('cached vaulter.cas = '.json_encode($cached['vaulter']['cas'] ?? '(missing)'));

    // Reboot a FRESH application instance under the cache that was just dumped.
    $this->refreshApplication();

    dump('configurationIsCached() after reboot = '.var_export($this->app->configurationIsCached(), true));
    dump('media provider loaded after reboot = '.var_export(
        $this->app->providerIsLoaded(\Vaulter\Media\VaulterMediaServiceProvider::class),
        true,
    ));

    try {
        $manager = $this->app->make(VaulterManager::class);
        $driver = $this->app->make(HostRoleDriver::class);
        $registry = $this->app->make(PanelRegistry::class);

        dump('VaulterManager resolved = '.get_class($manager));
        dump('HostRoleDriver resolved = '.get_class($driver));
        dump('PanelRegistry default panel = '.$registry->default()->getId());
    } catch (\Throwable $e) {
        dump('RESOLUTION FAILED: '.$e::class.': '.$e->getMessage());
    }

    // This assertion is DELIBERATELY loose (always true) — this test's purpose is
    // the diagnostic dump above, cited verbatim in the finding. See the finding's
    // C13 verdict + "Не проверено" for the interpretation.
    expect(true)->toBeTrue();
});
