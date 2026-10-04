<?php

declare(strict_types=1);

use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Providers\BindingsRegistrar;
use Vaulter\VaulterManager;

it('registers the manager and file presentation seams', function (): void {
    (new BindingsRegistrar)->register($this->app);

    expect(app(VaulterManager::class))->toBe(app('vaulter'))
        ->and(app(FileHandlerRegistry::class))->toBeInstanceOf(FileHandlerRegistry::class)
        ->and(app(NodePresenter::class))->toBeInstanceOf(NodePresenter::class);
});
