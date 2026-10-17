<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Drive\Access\AccessResolver;

/**
 * The shape of the v1 HTTP layer (P6-invariants#Bypass): single-action controllers over
 * the operations, resources built from snapshots and given abilities, and one egress
 * point for bytes and signed URLs.
 */
const V1_CONTROLLER_NAMESPACES = [
    'Vaulter\Http\Controllers\Activity',
    'Vaulter\Http\Controllers\Changes',
    'Vaulter\Http\Controllers\Webhooks',
];

arch('v1 controllers are final single-action classes')
    ->expect(V1_CONTROLLER_NAMESPACES)
    ->toBeFinal()
    ->toHaveMethod('__invoke')
    ->toHaveSuffix('Controller');

it('gives v1 controllers no public method besides the constructor and __invoke', function (): void {
    $root = dirname(__DIR__, 2).'/src/Http/Controllers';
    $offenders = [];
    foreach ((new Finder)->files()->in([$root.'/Activity', $root.'/Changes', $root.'/Webhooks'])->name('*.php') as $file) {
        $class = 'Vaulter\\Http\\Controllers\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root) + 1));
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', '__invoke'], true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('drive-scoped v1 controllers go through the operations, never Actions or Repositories')
    ->expect(array_slice(V1_CONTROLLER_NAMESPACES, 0, 2))
    ->not->toUse(['Vaulter\Actions', 'Vaulter\Drive\Actions', 'Vaulter\Repositories', 'Vaulter\Drive\Repositories']);

arch('no v1 controller or resource asks the AccessResolver itself')
    ->expect([...V1_CONTROLLER_NAMESPACES, 'Vaulter\Http\Resources'])
    ->not->toUse(AccessResolver::class);

arch('bytes leave only through the ContentResponder')
    ->expect([...V1_CONTROLLER_NAMESPACES, 'Vaulter\Http\Resources'])
    ->not->toUse([StreamedResponse::class, BinaryFileResponse::class, 'Vaulter\Content\ContentDelivery']);

it('mints no temporary or signed storage URL and streams no download in the HTTP layer outside the ContentResponder', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src/Http')->name('*.php') as $file) {
        if ($file->getFilename() === 'ContentResponder.php') {
            continue;
        }
        $code = $file->getContents();
        if (preg_match('/temporaryUrl\s*\(|streamDownload\s*\(|new\s+StreamedResponse\b/', $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('binds no route model in the package', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/routes'])->name('*.php') as $file) {
        if (preg_match('/Route::(bind|model)\s*\(/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
