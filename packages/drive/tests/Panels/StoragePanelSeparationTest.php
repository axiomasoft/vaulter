<?php

declare(strict_types=1);

use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * One owner, two panels, two DIFFERENT storages. The panel is registered in
 * PanelTestCase::defineEnvironment(), i.e. BEFORE boot: ProfileRegistry is resolved
 * in packageBooted() (failing loudly on a broken panel), so a runtime
 * config()->set('vaulter-drive.profiles.providers') after boot is no longer picked up. The test
 * used to rely on exactly that, and broke as soon as the registry started being built at boot.
 */
it('keeps one owner storages apart across panels', function (): void {
    $host = DriveTestHelpers::host();

    $default = Vaulter::drive($host, 'default')->model();
    $team = Vaulter::drive($host, 'team')->model();

    expect($team->getKey())->not->toEqual($default->getKey())
        ->and($team->profile)->toBe('team')
        ->and(Drive::query()->count())->toBe(2);
});
