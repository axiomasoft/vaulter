<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Vaulter\Drive\Activity\RecordsDriveActivity;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Events\Uploads\UploadInitiated;
use Vaulter\Drive\Http\Controllers\Drives\ShowDriveController;
use Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController;
use Vaulter\Drive\Http\Controllers\Uploads\TusProtocolController;
use Vaulter\Drive\Providers\HttpRegistrar;
use Vaulter\Drive\Webhooks\RecordsDriveOutbox;
use Vaulter\Http\Middleware\ResolveVaulterRouteParameters;

it('registers each subscriber only when its own feature is enabled', function (bool $activity, bool $reliability): void {
    config()->set('vaulter.features.activity', $activity);
    config()->set('vaulter.features.webhooks', $reliability);
    $original = Event::getFacadeRoot();
    $events = new Dispatcher($this->app);
    Event::swap($events);

    try {
        (new HttpRegistrar)->boot($this->app);

        $listeners = [];

        if ($activity) {
            $listeners[] = [RecordsDriveActivity::class, 'handleNodeCreated'];
        }

        if ($reliability) {
            $listeners[] = [RecordsDriveOutbox::class, 'handleNodeCreated'];
        }

        expect($events->getRawListeners()[FolderCreated::class] ?? [])->toBe($listeners)
            ->and($events->getRawListeners()[UploadInitiated::class] ?? [])->toBe(
                $activity ? [[RecordsDriveActivity::class, 'handleUploadSessionInitiated']] : [],
            );
    } finally {
        Event::swap($original);
    }
})->with([
    'both disabled' => [false, false],
    'activity only' => [true, false],
    'reliability only' => [false, true],
    'both enabled' => [true, true],
]);

it('declares the existing routes and commands and preserves route feature gates', function (bool $uploads, bool $grants): void {
    config()->set('vaulter.routes.prefix', 'host/drive');
    config()->set('vaulter.routes.middleware.authenticated', ['host.auth']);
    config()->set('vaulter-drive.uploads.resumable.enabled', $uploads);
    config()->set('vaulter.features.grants', $grants);
    $package = new Package;
    (new HttpRegistrar)->configure($package);

    expect($package->routeFileNames)->toBe(['drive'])
        ->and($package->commands)->toBe([PurgeTrashCommand::class, PruneRevisionsCommand::class, SweepUploadsCommand::class]);

    $original = Route::getFacadeRoot();
    $router = new Router(new Dispatcher($this->app), $this->app);
    Route::swap($router);

    try {
        foreach ($package->routeFileNames as $file) {
            require __DIR__.'/../../routes/'.$file.'.php';
        }

        $routes = collect($router->getRoutes()->getRoutes());
        $show = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/storages/{storage}');

        expect($show)->not->toBeNull()
            ->and($show->getAction('uses'))->toBe(ShowDriveController::class.'@__invoke')
            ->and($show->getControllerClass())->toBe(ShowDriveController::class)
            ->and($show->methods())->toBe(['GET', 'HEAD'])
            ->and($show->middleware())->toContain('host.auth', ResolveVaulterRouteParameters::class)
            ->and($show->getName())->toBeNull()
            ->and($routes->contains(fn ($route): bool => $route->uri() === 'host/drive/shared'))->toBe($grants);

        $tus = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/storages/{storage}/resumable-uploads');
        $multipart = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/resumable-uploads/{upload_id}/complete');

        if (!$uploads) {
            expect($tus)->toBeNull()->and($multipart)->toBeNull();

            return;
        }

        expect($tus)->not->toBeNull()
            ->and($tus->getActionName())->toBe(TusProtocolController::class.'@create')
            ->and($tus->middleware())->toContain('host.auth', 'throttle:vaulter-uploads')
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
