<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Drive\Access\AccessResolver;

/**
 * The shape of the documents v1 HTTP layer (P6-invariants#Bypass): single-action
 * controllers over the operations, resources built from snapshots, no route model
 * binding, and bytes only through the core ContentResponder.
 */
const DOCUMENTS_V1_CONTROLLER_NAMESPACES = [
    'Vaulter\Documents\Http\Controllers\Documents',
    'Vaulter\Documents\Http\Controllers\Revisions',
    'Vaulter\Documents\Http\Controllers\Comments',
];

arch('documents v1 controllers are final single-action classes')
    ->expect(DOCUMENTS_V1_CONTROLLER_NAMESPACES)
    ->toBeFinal()
    ->toHaveMethod('__invoke')
    ->toHaveSuffix('Controller');

it('gives documents v1 controllers no public method besides the constructor and __invoke', function (): void {
    $root = dirname(__DIR__, 2).'/src/Http/Controllers';
    $offenders = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $class = 'Vaulter\\Documents\\Http\\Controllers\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', '__invoke'], true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('documents v1 controllers and resources go through the operations, never Actions or Repositories')
    ->expect([...DOCUMENTS_V1_CONTROLLER_NAMESPACES, 'Vaulter\Documents\Http\Resources'])
    ->not->toUse(['Vaulter\Actions', 'Vaulter\Documents\Actions', 'Vaulter\Repositories', 'Vaulter\Documents\Repositories']);

arch('no documents controller or resource asks the AccessResolver itself')
    ->expect(['Vaulter\Documents\Http'])
    ->not->toUse(AccessResolver::class);

arch('documents controllers hand out bytes only through the ContentResponder')
    ->expect(['Vaulter\Documents\Http'])
    ->not->toUse([StreamedResponse::class, BinaryFileResponse::class, 'Vaulter\Drive\Content\ContentDelivery']);

arch('the legacy unversioned controllers and the removed middleware stay removed')
    ->expect([
        'Vaulter\Documents\Http\Controllers\Documents\DocumentNodeController',
        'Vaulter\Documents\Http\Controllers\Comments\NodeCommentController',
        'Vaulter\Http\Middleware\ResolveVaulterRouteParameters',
    ])
    ->not->toBeUsed();

it('binds no route model and uses no binding middleware in the documents routes', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/documents.php');
    $offenders = [];
    foreach ((new Finder)->files()->in([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/routes'])->name('*.php') as $file) {
        if (preg_match('/Route::(bind|model)\s*\(/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([])
        ->and($routes)->not->toContain('SubstituteBindings')->not->toContain('ResolveVaulterRouteParameters');
});

it('names every documents route vaulter.v1.* under the versioned prefix', function (): void {
    $documents = array_filter(
        iterator_to_array(app('router')->getRoutes()),
        static fn (Route $route): bool => str_starts_with((string) $route->getActionName(), 'Vaulter\Documents\\'),
    );

    expect($documents)->not->toBeEmpty();
    foreach ($documents as $route) {
        expect($route->getName())->toStartWith('vaulter.v1.')
            ->and($route->uri())->toStartWith('api/vaulter/v1/');
    }
});
