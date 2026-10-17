<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Symfony\Component\Finder\Finder;
use Vaulter\Drive\Access\AccessResolver;

/**
 * The shape of the collaboration HTTP layer: single-action controllers; the session goes through the operations of the
 * drive handle, the hub's three through the collaboration hub, the one service that re-checks a room on every call.
 */
const COLLABORATION_V1_CONTROLLER_NAMESPACES = ['Vaulter\Collaboration\Http\Controllers\Collaboration'];

arch('collaboration v1 controllers are final single-action classes')
    ->expect(COLLABORATION_V1_CONTROLLER_NAMESPACES)
    ->toBeFinal()
    ->toHaveMethod('__invoke')
    ->toHaveSuffix('Controller');

it('gives collaboration v1 controllers no public method besides the constructor and __invoke', function (): void {
    $root = dirname(__DIR__, 2).'/src/Http/Controllers';
    $offenders = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $class = 'Vaulter\\Collaboration\\Http\\Controllers\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', '__invoke'], true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('collaboration controllers never reach for Repositories or the AccessResolver themselves')
    ->expect(COLLABORATION_V1_CONTROLLER_NAMESPACES)
    ->not->toUse(['Vaulter\Repositories', 'Vaulter\Documents\Repositories', AccessResolver::class]);

it('names every collaboration route vaulter.v1.* under the versioned prefix', function (): void {
    $routes = array_filter(
        iterator_to_array(app('router')->getRoutes()),
        static fn (Route $route): bool => str_starts_with((string) $route->getActionName(), 'Vaulter\Collaboration\\'),
    );

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        expect($route->getName())->toStartWith('vaulter.v1.')
            ->and($route->uri())->toStartWith('api/vaulter/v1/');
    }
});
