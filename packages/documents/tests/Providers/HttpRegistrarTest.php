<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Http\Controllers\Documents\UpdateDocumentContentController;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;

it('declares the existing route file during package configuration and mounts installed document routes', function (): void {
    $module = new ModuleBuilder(new ModuleId('documents'));
    (new DocumentsModule)->register($module);
    expect(array_map(realpath(...), $module->routeFiles()))->toBe([realpath(__DIR__.'/../../routes/documents.php')]);

    config()->set('vaulter.routes.prefix', 'host/documents');
    config()->set('vaulter.routes.middleware.authenticated', ['host.auth']);
    config()->set('vaulter-documents.comments.enabled', false);
    $original = Route::getFacadeRoot();
    $router = new Router(new Dispatcher($this->app), $this->app);
    Route::swap($router);

    try {
        require __DIR__.'/../../routes/documents.php';
        $routes = collect($router->getRoutes()->getRoutes());
        $route = $routes->first(fn ($route): bool => $route->uri() === 'host/documents/v1/documents/{node}/content' && in_array('PUT', $route->methods(), true));

        expect($route)->not->toBeNull()
            ->and($route->getName())->toBe('vaulter.v1.documents.content.update')
            ->and($route->getActionName())->toBe(UpdateDocumentContentController::class)
            ->and($route->middleware())->toContain('host.auth')
            // A disabled feature never unregisters a route: the operations refuse it.
            ->and($routes->contains(fn ($route): bool => $route->getName() === 'vaulter.v1.comments.index'))->toBeTrue()
            // The realtime session and the hub stack are the routes of the collaboration package, not of this one.
            ->and($routes->contains(fn ($route): bool => str_contains((string) $route->getName(), 'collaboration')))->toBeFalse();
    } finally {
        Route::swap($original);
    }
});
