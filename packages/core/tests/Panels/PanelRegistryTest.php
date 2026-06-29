<?php

declare(strict_types=1);

use Vaulter\Exceptions\InvalidPanelException;
use Vaulter\Exceptions\UnknownPanelException;
use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Panels\StoragePanelProvider;
use Vaulter\Tests\Fixtures\Panels\OverrideDefaultStoragePanel;
use Vaulter\Tests\Fixtures\Panels\ProjectStoragePanel;
use Vaulter\Tests\Fixtures\Panels\TeamStoragePanel;

it('строит реестр из конфига и регистрирует дефолт первым', function (): void {
    $registry = PanelRegistry::fromConfig(
        providerClasses: [ProjectStoragePanel::class, TeamStoragePanel::class],
        defaultClass: DefaultStoragePanel::class,
    );

    expect($registry->has('default'))->toBeTrue()
        ->and($registry->has('project'))->toBeTrue()
        ->and($registry->has('team'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and(array_keys($registry->all()))->toBe(['default', 'project', 'team'])
        ->and($registry->default()->getId())->toBe('default');
});

it('явная панель переопределяет дефолт, зарегистрированный первым', function (): void {
    $registry = PanelRegistry::fromConfig(
        providerClasses: [OverrideDefaultStoragePanel::class],
        defaultClass: DefaultStoragePanel::class,
    );

    expect($registry->all())->toHaveCount(1)
        ->and($registry->default()->storageTypeKey())->toBe('overridden');
});

it('зовёт хук booted после регистрации провайдера', function (): void {
    ProjectStoragePanel::$bootedId = null;

    PanelRegistry::fromConfig(
        providerClasses: [ProjectStoragePanel::class],
        defaultClass: DefaultStoragePanel::class,
    );

    expect(ProjectStoragePanel::$bootedId)->toBe('project');
});

it('get бросает UnknownPanelException на неизвестную панель', function (): void {
    $registry = PanelRegistry::fromConfig(
        providerClasses: [],
        defaultClass: DefaultStoragePanel::class,
    );

    $registry->get('nope');
})->throws(UnknownPanelException::class, 'Unknown Vaulter storage panel [nope]');

it('отвергает панель с per-panel idStrategy на общей схеме', function (): void {
    $provider = new class extends StoragePanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel->id('bad')->idStrategy('uuid7');
        }
    };

    (new PanelRegistry)->registerProvider($provider);
})->throws(InvalidPanelException::class, 'incoherent on the shared');

it('разрешает per-panel idStrategy в режиме своих таблиц', function (): void {
    $registry = new PanelRegistry;

    $registry->register(
        (new Panel)->id('own')->tablePrefix('own_drive')->idStrategy('uuid7'),
    );

    expect($registry->get('own')->idStrategyKey())->toBe('uuid7');
});

it('отвергает панель, ссылающуюся на несуществующий класс модели', function (): void {
    $registry = new PanelRegistry;

    /** @var array<string, class-string> $map */
    $map = ['nodes' => 'App\\Does\\Not\\Exist'];

    $registry->register((new Panel)->id('bad')->models($map));
})->throws(InvalidPanelException::class, 'does not exist');

it('отвергает панель, чья модель не наследует Eloquent Model', function (): void {
    $registry = new PanelRegistry;

    $registry->register((new Panel)->id('bad')->models(['nodes' => stdClass::class]));
})->throws(InvalidPanelException::class, 'must be a');
