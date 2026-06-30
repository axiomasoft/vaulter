<?php

declare(strict_types=1);

use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Panels\Panel;
use Vaulter\Tests\Fixtures\Panels\FakeProject;
use Vaulter\Tests\Fixtures\Panels\ProjectNode;

it('резолвит таблицу из общей схемы, когда у панели нет своих таблиц', function (): void {
    $panel = (new DefaultStoragePanel)->panel(new Panel);

    expect($panel->hasOwnTables())->toBeFalse()
        ->and($panel->table('nodes'))->toBe('v_nodes')
        ->and($panel->table('storages'))->toBe('v_storages');
});

it('префикс панели бьёт общую схему, но карта таблиц бьёт префикс', function (): void {
    $prefixed = (new Panel)->id('p')->tablePrefix('proj_drive');

    expect($prefixed->hasOwnTables())->toBeTrue()
        ->and($prefixed->table('nodes'))->toBe('proj_drive_nodes');

    $mapped = (new Panel)->id('p')->tablePrefix('proj_drive')->tables(['nodes' => 'custom_nodes']);

    expect($mapped->table('nodes'))->toBe('custom_nodes')
        ->and($mapped->table('storages'))->toBe('proj_drive_storages');
});

it('резолвит модель из карты панели, иначе из конфига, иначе null', function (): void {
    $mapped = (new Panel)->id('p')->models(['nodes' => ProjectNode::class]);

    expect($mapped->model('nodes'))->toBe(ProjectNode::class);

    config()->set('vaulter.models.node', FakeProject::class);

    expect((new Panel)->id('p')->model('node'))->toBe(FakeProject::class)
        ->and((new Panel)->id('p')->model('does_not_exist'))->toBeNull();
});

it('idStrategyKey берёт override панели, иначе глобальный дефолт', function (): void {
    // The base suite pins bigint as the global default.
    expect((new Panel)->id('p')->idStrategyKey())->toBe('bigint')
        ->and((new Panel)->id('p')->idStrategy('uuid7')->idStrategyKey())->toBe('uuid7');
});

it('hasOwnTables истинно при префиксе или карте таблиц', function (): void {
    expect((new Panel)->hasOwnTables())->toBeFalse()
        ->and((new Panel)->tablePrefix('x')->hasOwnTables())->toBeTrue()
        ->and((new Panel)->tables(['nodes' => 'x_nodes'])->hasOwnTables())->toBeTrue();
});

it('применяет дефолты фич Vaulter с override панели', function (): void {
    $panel = (new Panel)->id('p');

    expect($panel->featureEnabled('grants'))->toBeTrue()
        ->and($panel->featureEnabled('versions'))->toBeTrue()
        ->and($panel->featureEnabled('blobs'))->toBeTrue()
        ->and($panel->featureEnabled('activity'))->toBeTrue()
        ->and($panel->featureEnabled('uploads'))->toBeTrue()
        ->and($panel->featureEnabled('extra_data'))->toBeTrue()
        ->and($panel->featureEnabled('reliability'))->toBeTrue()
        ->and($panel->featureEnabled('share_links'))->toBeFalse()
        ->and($panel->featureEnabled('public_listing'))->toBeFalse()
        ->and($panel->featureEnabled('document_versions'))->toBeFalse();

    // grants follows hasGrants; explicit override wins.
    expect((new Panel)->withoutGrants()->featureEnabled('grants'))->toBeFalse()
        ->and((new Panel)->withoutGrants()->feature('grants', true)->featureEnabled('grants'))->toBeTrue()
        ->and((new Panel)->feature('share_links', true)->featureEnabled('share_links'))->toBeTrue();
});

it('хранит host, storage type и grants', function (): void {
    $panel = (new Panel)->id('p')->host(FakeProject::class)->storageType('team');

    expect($panel->getId())->toBe('p')
        ->and($panel->hostModel())->toBe(FakeProject::class)
        ->and($panel->storageTypeKey())->toBe('team')
        ->and($panel->hasGrants())->toBeTrue()
        ->and((new Panel)->withoutGrants()->hasGrants())->toBeFalse();
});
