<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Drive\Contracts\Handlers\NodePresenter;
use Vaulter\Drive\Providers\DriveSliceRegistrar;
use Vaulter\Drive\Vaulter;

// The drive slices located in core are wired by DriveSliceRegistrar, which DriveModule declares.

it('registers the manager and file presentation seams', function (): void {
    (new DriveSliceRegistrar)->register($this->app);

    expect(app(Vaulter::class))->toBe(app('vaulter'))
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
