<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Broadcast;
use Vaulter\Documents\Collaboration\HostUserResolver as DefaultHostUserResolver;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\Providers\CollaborationRegistrar;

it('binds the host user resolver in an isolated container', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance(AuthFactory::class, $this->app->make(AuthFactory::class));

    (new CollaborationRegistrar($app))->register();

    expect($app->make(HostUserResolver::class))->toBeInstanceOf(DefaultHostUserResolver::class);
});

it('preserves the channel prefix and rejects sessions when collaboration is disabled', function (bool $collaboration): void {
    config()->set('vaulter-documents.collaboration.broadcast.channel_prefix', 'host.documents');
    config()->set('vaulter-documents.collaboration.enabled', $collaboration);
    $callback = null;
    Broadcast::shouldReceive('channel')->once()->withArgs(function (string $name, Closure $authorize) use (&$callback): bool {
        $callback = $authorize;

        return $name === 'host.documents.{roomId}';
    });

    (new CollaborationRegistrar($this->app))->boot();

    expect($callback)->toBeInstanceOf(Closure::class)
        ->and($callback(Mockery::mock(Authenticatable::class), 123))->toBeFalse();
})->with([false, true]);
