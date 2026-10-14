<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Providers\ConsoleRegistrar;

it('registers the drive commands still located in core, not the webhook commands', function (): void {
    (new ConsoleRegistrar)->register($this->app);

    // The webhook commands are declared by WebhooksModule and registered only while it is active (D22).
    expect(Artisan::all())->toHaveKeys(['vaulter:make:profile', 'vaulter:changes:prune'])
        ->not->toHaveKey('vaulter:webhooks:relay')
        ->not->toHaveKey('vaulter:make-panel')
        ->not->toHaveKey('vaulter:panel:migrations');
});
