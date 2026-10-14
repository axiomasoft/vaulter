<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Providers\DriveSliceRegistrar;
use Vaulter\VaulterManager;

// The drive slices located in core (D39) are wired by DriveSliceRegistrar, which DriveModule declares.

it('registers the manager and file presentation seams', function (): void {
    (new DriveSliceRegistrar)->register($this->app);

    expect(app(VaulterManager::class))->toBe(app('vaulter'))
        ->and(app(FileHandlerRegistry::class))->toBeInstanceOf(FileHandlerRegistry::class)
        ->and(app(NodePresenter::class))->toBeInstanceOf(NodePresenter::class);
});

it('preserves host rate limiters when booted again', function (): void {
    $hostLimiter = static fn (): string => 'host';
    RateLimiter::for('vaulter-public', $hostLimiter);
    (new DriveSliceRegistrar)->boot($this->app);

    expect(RateLimiter::limiter('vaulter-public')())->toBe('host')
        ->and(RateLimiter::limiter('vaulter-uploads'))->not->toBeNull();
});
