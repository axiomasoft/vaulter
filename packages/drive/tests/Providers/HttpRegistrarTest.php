<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Vaulter\Drive\Console\PruneRevisionsCommand;
use Vaulter\Drive\Console\PurgeDrivesCommand;
use Vaulter\Drive\Console\PurgeTrashCommand;
use Vaulter\Drive\Console\RecountDrivesCommand;
use Vaulter\Drive\Console\SweepUploadsCommand;
use Vaulter\Drive\DriveModule;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Events\Uploads\UploadInitiated;
use Vaulter\Drive\Http\Controllers\Drives\ShowDriveController;
use Vaulter\Drive\Http\Controllers\Uploads\CreateUploadController;
use Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController;
use Vaulter\Events\EventRecorder;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;

it('registers no event subscriber: events are recorded by the EventRecorder', function (bool $activity): void {
    config()->set('vaulter-drive.features.activity', $activity);
    $this->app->forgetInstance(EventRecorder::class);

    expect(Event::hasListeners(FolderCreated::class))->toBeFalse()
        ->and(Event::hasListeners(UploadInitiated::class))->toBeFalse();
})->with([
    'activity disabled' => [false],
    'activity enabled' => [true],
]);

it('declares the v1 routes and commands, registered whatever the feature flags say', function (bool $uploads, bool $grants): void {
    config()->set('vaulter.routes.prefix', 'host/drive');
    config()->set('vaulter.routes.middleware.authenticated', ['host.auth']);
    config()->set('vaulter-drive.uploads.resumable.enabled', $uploads);
    config()->set('vaulter-drive.features.grants', $grants);
    $module = new ModuleBuilder(new ModuleId('drive'));
    (new DriveModule)->register($module);
    $driveRoutes = realpath(__DIR__.'/../../routes/drive.php');

    // The grants, shares, activity and change-feed routes live in the same file since P4.11: the module declares no other.
    expect(array_map(realpath(...), $module->routeFiles()))->toBe([$driveRoutes])
        ->and($module->commandClasses())->toContain(PurgeTrashCommand::class, PurgeDrivesCommand::class, RecountDrivesCommand::class, PruneRevisionsCommand::class, SweepUploadsCommand::class);

    $original = Route::getFacadeRoot();
    $router = new Router(new Dispatcher($this->app), $this->app);
    Route::swap($router);

    try {
        require $driveRoutes;

        $routes = collect($router->getRoutes()->getRoutes());
        $show = $routes->first(fn ($route): bool => $route->uri() === 'host/drive/v1/drives/{drive}');

        expect($show)->not->toBeNull()
            ->and($show->getAction('uses'))->toBe(ShowDriveController::class.'@__invoke')
            ->and($show->methods())->toBe(['GET', 'HEAD'])
            ->and($show->middleware())->toContain('host.auth')
            ->and($show->getName())->toBe('vaulter.v1.drives.show')
            // A feature flag is the operations' decision, so route:cache never freezes it.
            ->and($routes->contains(fn ($route): bool => $route->uri() === 'host/drive/v1/shared'))->toBeTrue()
            ->and($routes->map(fn ($route): string => $route->uri())->all())->toContain('host/drive/v1/nodes/{node}/grants', 'host/drive/v1/nodes/{node}/shares', 'host/drive/v1/drives/{drive}/changes', 'host/drive/v1/shares/{token}');

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
