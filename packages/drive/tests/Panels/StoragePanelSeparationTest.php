<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;

/*
 * Один владелец, две панели — два РАЗНЫХ storage (§2.1). Панель регистрируется в
 * PanelTestCase::defineEnvironment(), то есть ДО boot'а: с P4.4 PanelRegistry резолвится
 * в packageBooted() (fail-loud на битой панели, F-P3.2-01), поэтому рантаймовый
 * config()->set('vaulter.panels') после boot'а уже не подхватывается. Раньше тест держался
 * именно на нём — и упал, как только реестр стал строиться на boot'е.
 */
it('разводит storage по разным панелям одного владельца', function (): void {
    $host = DriveTestHelpers::host();

    $default = Vaulter::for($host, 'default')->storage();
    $team = Vaulter::for($host, 'team')->storage();

    expect($team->getKey())->not->toEqual($default->getKey())
        ->and($team->panel)->toBe('team')
        ->and($team->storage_type)->toBe('public')
        ->and(Storage::query()->count())->toBe(2);
});
