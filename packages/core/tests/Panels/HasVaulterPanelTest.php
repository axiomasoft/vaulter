<?php

declare(strict_types=1);

use Vaulter\Models\Concerns\HasVaulterPanel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Tests\Fixtures\Panels\NodeFixture;
use Vaulter\Tests\Fixtures\Panels\ProjectNode;
use Vaulter\Tests\Fixtures\Panels\ProjectStoragePanel;

beforeEach(function (): void {
    app(PanelRegistry::class)->registerProvider(new ProjectStoragePanel);
});

it('резолвит таблицу через объявленную панель', function (): void {
    expect((new ProjectNode)->getTable())->toBe('project_drive_nodes');
});

it('берёт стратегию ключа панели в режиме своих таблиц', function (): void {
    // ProjectStoragePanel has own tables and pins uuid7.
    $node = new ProjectNode;

    expect($node->getKeyType())->toBe('string')
        ->and($node->getIncrementing())->toBeFalse();
});

it('базовая модель без панели резолвит общую схему', function (): void {
    expect((new NodeFixture)->getTable())->toBe('v_nodes');
});

it('инертна, когда панель не объявлена', function (): void {
    $model = new class extends NodeFixture
    {
        use HasVaulterPanel;
    };

    expect($model->getTable())->toBe('v_nodes');
});
