<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Vaulter\Documents\Http\Controllers\Documents\DocumentNodeController;
use Vaulter\Documents\Providers\HttpRegistrar;

it('declares the existing route file during package configuration and mounts installed document routes', function (): void {
    $package = new Package;
    (new HttpRegistrar)->configure($package);
    expect($package->routeFileNames)->toBe(['documents']);

    config()->set('vaulter.routes.prefix', 'host/documents');
    config()->set('vaulter.routes.middleware.authenticated', ['host.auth']);
    config()->set('vaulter-documents.collaboration.enabled', false);
    config()->set('vaulter-documents.comments.enabled', false);
    $original = Route::getFacadeRoot();
    $router = new Router(new Dispatcher($this->app), $this->app);
    Route::swap($router);

    try {
        require __DIR__.'/../../routes/documents.php';
        $route = collect($router->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'host/documents/nodes/{node}/content' && in_array('PATCH', $route->methods(), true));

        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toBe(DocumentNodeController::class.'@update')
            ->and($route->middleware())->toContain('host.auth');
    } finally {
        Route::swap($original);
    }
});
