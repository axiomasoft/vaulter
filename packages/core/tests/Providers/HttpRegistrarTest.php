<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Vaulter\Providers\HttpRegistrar;

it('preserves host rate limiters when booted again', function (): void {
    $hostLimiter = static fn (): string => 'host';
    RateLimiter::for('vaulter-public', $hostLimiter);
    (new HttpRegistrar)->boot($this->app);

    expect(RateLimiter::limiter('vaulter-public')())->toBe('host')
        ->and(RateLimiter::limiter('vaulter-uploads'))->not->toBeNull();
});
