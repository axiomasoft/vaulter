<?php

declare(strict_types=1);

use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * Один владелец, две панели — два РАЗНЫХ storage. Панель регистрируется в
 * PanelTestCase::defineEnvironment(), то есть ДО boot'а: ProfileRegistry резолвится
 * в packageBooted() (fail-loud на битой панели), поэтому рантаймовый
 * config()->set('vaulter-drive.profiles.providers') после boot'а уже не подхватывается. Раньше тест держался
 * именно на нём — и упал, как только реестр стал строиться на boot'е.
 */
it('разводит storage по разным панелям одного владельца', function (): void {
    $host = DriveTestHelpers::host();

    $default = Vaulter::drive($host, 'default')->model();
    $team = Vaulter::drive($host, 'team')->model();

    expect($team->getKey())->not->toEqual($default->getKey())
        ->and($team->profile)->toBe('team')
        ->and(Drive::query()->count())->toBe(2);
});
