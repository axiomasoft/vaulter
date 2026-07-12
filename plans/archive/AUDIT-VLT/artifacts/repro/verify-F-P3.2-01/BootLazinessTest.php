<?php

declare(strict_types=1);

/**
 * Repro для F-P3.2-01 (скептик-верификация).
 *
 * Претензия ADR-0009 (Decision, п.3) и докблока InvalidPanelException:
 * кривая панель «fails loud at boot (not at runtime)».
 *
 * Тест регистрирует ЗАВЕДОМО битую панель (models() на несуществующий FQCN)
 * через config('vaulter.panels') — т.е. ровно тем путём, которым хост
 * подключает панель в проде — и проверяет:
 *   1) приложение (все провайдеры пакета register()+boot()) поднимается
 *      БЕЗ исключения;
 *   2) InvalidPanelException вылетает только на первом РЕАЛЬНОМ обращении
 *      к PanelRegistry (app(PanelRegistry::class)), т.е. потенциально уже
 *      внутри живого запроса — не на boot.
 *
 * Запуск:
 *   /usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/verify-F-P3.2-01/BootLazinessTest.php
 */

use Vaulter\Exceptions\Panels\InvalidPanelException;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelRegistry;
use Vaulter\Panels\StoragePanelProvider;
use Vaulter\Tests\TestCase;

final class BrokenBootPanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('broken')
            ->models(['nodes' => 'App\\Does\\Not\\Exist']);
    }
}

class F_P3_2_01_BootLazinessTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Ровно так хост подключает панель в проде: FQCN в конфиге.
        $app['config']->set('vaulter.panels', [BrokenBootPanel::class]);
    }
}

uses(F_P3_2_01_BootLazinessTestCase::class);

it('boot приложения с битой панелью в конфиге НЕ бросает InvalidPanelException', function (): void {
    // К этой точке PackageServiceProvider::register()+boot() пакета Vaulter
    // (включая VaulterServiceProvider::packageRegistered/packageBooted) УЖЕ
    // отработали — Testbench поднимает приложение в setUp() до первого теста.
    expect($this->app->isBooted())->toBeTrue();

    // Явное доказательство: PanelRegistry ещё не резолвился (singleton не
    // инстанциирован) — container не хранит resolved-инстанс.
    expect($this->app->resolved(PanelRegistry::class))->toBeFalse();
});

it('InvalidPanelException вылетает ТОЛЬКО на первом фактическом резолве PanelRegistry', function (): void {
    // Первое реальное обращение — то, что происходит внутри HasVaulterPanel/
    // StorageManager/Config::featureEnabled() на живом запросе, а не на boot.
    expect(fn () => $this->app->make(PanelRegistry::class))
        ->toThrow(InvalidPanelException::class, 'does not exist');
});
