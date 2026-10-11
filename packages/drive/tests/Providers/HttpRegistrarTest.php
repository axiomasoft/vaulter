<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Events\Uploads\UploadInitiated;
use Vaulter\Drive\Http\Controllers\Drives\ShowDriveController;
use Vaulter\Drive\Http\Controllers\Uploads\CreateUploadController;
use Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController;
use Vaulter\Drive\Providers\HttpRegistrar;

it('registers no event subscriber: events are recorded by the EventRecorder', function (bool $activity, bool $webhooks): void {
    config()->set('vaulter.features.activity', $activity);
    config()->set('vaulter.features.webhooks', $webhooks);

    expect(Event::hasListeners(FolderCreated::class))->toBeFalse()
        ->and(Event::hasListeners(UploadInitiated::class))->toBeFalse();
})->with([
    'both disabled' => [false, false],
    'both enabled' => [true, true],
]);

it('declares the v1 routes and commands, registered whatever the feature flags say', function (bool $uploads, bool $grants): void {
    config()->set('vaulter.routes.prefix', 'host/drive');
    config()->set('vaulter.routes.middleware.authenticated', ['host.auth']);
    config()->set('vaulter-drive.uploads.resumable.enabled', $uploads);
    config()->set('vaulter.features.grants', $grants);
    $package = new Package;
    (new HttpRegistrar)->configure($package);

    expect($package->routeFileNames)->toBe(['drive'])
        ->and($package->commands)->toBe([PurgeTrashCommand::class, PurgeDrivesCommand::class, RecountDrivesCommand::class, PruneRevisionsCommand::class, SweepUploadsCommand::class]);

    $original = Route::getFacadeRoot();
    $router = new Router(new Dispatcher($this->app), $this->app);
    Route::swap($router);

    try {
        foreach ($package->routeFileNames as $file) {
            require __DIR__.'/../../routes/'.$file.'.php';
        }

        $routes = collect($router->getRoutes()->getRoutes());
        $show = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/v1/drives/{drive}');

        expect($show)->not->toBeNull()
            ->and($show->getAction('uses'))->toBe(ShowDriveController::class.'@__invoke')
            ->and($show->methods())->toBe(['GET', 'HEAD'])
            ->and($show->middleware())->toContain('host.auth')
            ->and($show->getName())->toBe('vaulter.v1.drives.show')
            // A feature flag is the operations' decision, so route:cache never freezes it.
            ->and($routes->contains(fn ($route): bool => $route->uri() === 'host/drive/v1/shared'))->toBeTrue();

        $create = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/v1/drives/{drive}/uploads');
        $multipart = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/v1/uploads/{upload}/complete');

        expect($create)->not->toBeNull()
            ->and($create->getActionName())->toBe(CreateUploadController::class)
            ->and($create->getName())->toBe('vaulter.v1.uploads.store')
            ->and($create->middleware())->toContain('host.auth', 'throttle:vaulter-uploads')
            ->and($multipart)->not->toBeNull()
            ->and($multipart->getActionName())->toBe(S3MultipartProtocolController::class.'@complete');
    } finally {
        Route::swap($original);
    }
})->with([
    'both disabled' => [false, false],
    'uploads only' => [true, false],
    'grants only' => [false, true],
    'both enabled' => [true, true],
]);
