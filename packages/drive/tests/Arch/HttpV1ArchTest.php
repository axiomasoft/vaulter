<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Drive\Access\AccessResolver;

/**
 * The shape of the drive v1 HTTP layer (P6-invariants#Bypass): single-action controllers
 * over the operations (the tus and S3 protocol controllers keep their acts), resources
 * built from snapshots and given abilities, no route model binding, and bytes only
 * through the core ContentResponder.
 */
const DRIVE_V1_CONTROLLER_NAMESPACES = [
    'Vaulter\Drive\Http\Controllers\Access',
    'Vaulter\Drive\Http\Controllers\Sharing',
    'Vaulter\Drive\Http\Controllers\Drives',
    'Vaulter\Drive\Http\Controllers\Nodes',
    'Vaulter\Drive\Http\Controllers\Search',
    'Vaulter\Drive\Http\Controllers\Shared',
    'Vaulter\Drive\Http\Controllers\Folders',
    'Vaulter\Drive\Http\Controllers\Files',
    'Vaulter\Drive\Http\Controllers\Trash',
    'Vaulter\Drive\Http\Controllers\Uploads\CreateUploadController',
];

arch('drive v1 controllers are final single-action classes')
    ->expect(DRIVE_V1_CONTROLLER_NAMESPACES)
    ->toBeFinal()
    ->toHaveMethod('__invoke')
    ->toHaveSuffix('Controller');

it('gives drive v1 controllers no public method besides the constructor and __invoke', function (): void {
    $root = dirname(__DIR__, 2).'/src/Http/Controllers';
    $offenders = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (in_array($relative, ['Uploads/TusProtocolController.php', 'Uploads/S3MultipartProtocolController.php'], true)) {
            continue;
        }
        $class = 'Vaulter\\Drive\\Http\\Controllers\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', '__invoke'], true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('drive v1 controllers and resources go through the operations, never Actions or Repositories')
    ->expect([...DRIVE_V1_CONTROLLER_NAMESPACES, 'Vaulter\Drive\Http\Resources'])
    ->not->toUse(['Vaulter\Actions', 'Vaulter\Drive\Actions', 'Vaulter\Repositories', 'Vaulter\Drive\Repositories']);

arch('the protocol controllers use the session repository only to find a session, never Actions')
    ->expect(['Vaulter\Drive\Http\Controllers\Uploads\TusProtocolController', 'Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController'])
    ->not->toUse(['Vaulter\Actions', 'Vaulter\Drive\Actions', 'Vaulter\Repositories']);

arch('no drive controller or resource asks the AccessResolver itself')
    ->expect(['Vaulter\Drive\Http'])
    ->not->toUse(AccessResolver::class);

arch('drive controllers hand out bytes only through the ContentResponder')
    ->expect(['Vaulter\Drive\Http'])
    ->not->toUse([StreamedResponse::class, BinaryFileResponse::class, 'Vaulter\Content\ContentDelivery']);

it('streams nothing and mints no storage URL in the drive HTTP layer', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src/Http')->name('*.php') as $file) {
        if (preg_match('/temporaryUrl\s*\(|streamDownload\s*\(|new\s+StreamedResponse\b/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('binds no route model and uses no binding middleware in the drive routes', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/drive.php');
    $offenders = [];
    foreach ((new Finder)->files()->in([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/routes'])->name('*.php') as $file) {
        if (preg_match('/Route::(bind|model)\s*\(/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([])
        ->and($routes)->not->toContain('SubstituteBindings')->not->toContain('ResolveVaulterRouteParameters');
});

it('names every drive route vaulter.v1.* under the versioned prefix', function (): void {
    $drive = array_filter(
        iterator_to_array(app('router')->getRoutes()),
        static fn (Route $route): bool => str_starts_with((string) $route->getActionName(), 'Vaulter\Drive\\'),
    );

    expect($drive)->not->toBeEmpty();
    foreach ($drive as $route) {
        expect($route->getName())->toStartWith('vaulter.v1.')
            ->and($route->uri())->toStartWith('api/vaulter/v1/');
    }
});
