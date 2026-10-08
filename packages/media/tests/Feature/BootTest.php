<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\VaulterMediaServiceProvider;

it('boots the media provider on top of core', function (): void {
    expect(app()->getProvider(VaulterMediaServiceProvider::class))
        ->not->toBeNull();
});

it('migrates the core-owned blob schema through the path-repo', function (): void {
    // The v_blobs table is owned by core; media loads it via the vendored core
    // migrations. If the path-repo wiring breaks, this fails loudly.
    expect(Schema::hasTable('v_blobs'))->toBeTrue();
});

it('registers the canonical CAS command names without legacy aliases', function (): void {
    expect(Artisan::all())->toHaveKeys(['vaulter:blobs:gc', 'vaulter:blobs:reconcile'])
        ->not->toHaveKey('vaulter:'.'gc-blobs')
        ->not->toHaveKey('vaulter:'.'reconcile-blobs');
});

it('refuses to boot with the retired global dedup scope', function (): void {
    config()->set('vaulter-media.dedup.scope', 'global');

    expect(fn () => (new VaulterMediaServiceProvider(app()))->packageBooted())
        ->toThrow(InvalidConfigurationException::class, 'repair-scopes');
});
