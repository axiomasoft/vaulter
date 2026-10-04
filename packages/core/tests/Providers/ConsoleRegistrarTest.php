<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Providers\ConsoleRegistrar;

it('registers profile scaffolding and webhook commands', function (): void {
    (new ConsoleRegistrar)->register($this->app);

    expect(Artisan::all())->toHaveKeys(['vaulter:make:profile', 'vaulter:relay-outbox', 'vaulter:retry-webhooks', 'vaulter:prune-webhooks'])
        ->not->toHaveKey('vaulter:make-panel')
        ->not->toHaveKey('vaulter:panel:migrations');
});
