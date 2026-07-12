<?php

declare(strict_types=1);

namespace Vaulter\Tests\Feature;

use Vaulter\Panels\PanelRegistry;

/**
 * Repro для верификации F-P3.2-01 («Validated on boot» не соответствует
 * фактическому ленивому резолву PanelRegistry). Цель: подтвердить/опровергнуть,
 * что PanelRegistry::class остаётся НЕразрешённым синглтоном сразу после
 * полного цикла boot() пакета (Testbench уже вызвал packageRegistered()/
 * packageBooted() к моменту, когда тело it() исполняется).
 */
it('PanelRegistry singleton is NOT resolved by app boot alone', function (): void {
    // К этому моменту Laravel/Testbench уже прогнал register()+boot() всех
    // провайдеров (включая VaulterServiceProvider::packageBooted()).
    expect(app()->resolved(PanelRegistry::class))->toBeFalse();

    // Первое реальное обращение резолвит (и тем самым валидирует) реестр —
    // подтверждает, что валидация происходит лениво, на первом использовании,
    // а не эагерно на boot.
    app(PanelRegistry::class);

    expect(app()->resolved(PanelRegistry::class))->toBeTrue();
});
