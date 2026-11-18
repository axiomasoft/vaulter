<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\Finder\Finder;

/**
 * The architecture of the webhooks package: the guards that stood on its code while it lived in the kernel
 * (single-action v1 controllers, writes through the operation runner, problem exceptions), plus the ownership
 * rule — the kernel never names this package.
 */
const WEBHOOK_V1_CONTROLLERS = 'Vaulter\Webhooks\Http\Controllers\Webhooks';

/** The webhook endpoint management writes, run through WebhookEndpointWrites. */
const WEBHOOK_ENDPOINT_WRITES = [
    'Vaulter\Webhooks\Http\Controllers\Webhooks\RegisterWebhookEndpointController',
    'Vaulter\Webhooks\Http\Controllers\Webhooks\UpdateWebhookEndpointController',
    'Vaulter\Webhooks\Http\Controllers\Webhooks\DeleteWebhookEndpointController',
    'Vaulter\Webhooks\Http\Controllers\Webhooks\DeactivateWebhookEndpointController',
    'Vaulter\Webhooks\Http\Controllers\Webhooks\RotateWebhookSecretController',
    'Vaulter\Webhooks\Http\Controllers\Webhooks\RedeliverWebhookController',
];

arch('v1 webhook controllers are final single-action classes')
    ->expect(WEBHOOK_V1_CONTROLLERS)
    ->toBeFinal()
    ->toHaveMethod('__invoke')
    ->toHaveSuffix('Controller');

it('gives the v1 webhook controllers no public method besides the constructor and __invoke', function (): void {
    $root = dirname(__DIR__, 2).'/src/Http/Controllers/Webhooks';
    $offenders = [];
    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $class = WEBHOOK_V1_CONTROLLERS.'\\'.$file->getBasename('.php');
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!in_array($method->getName(), ['__construct', '__invoke'], true)) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('runs the webhook endpoint management writes as operations', function (): void {
    foreach (WEBHOOK_ENDPOINT_WRITES as $controller) {
        $method = new ReflectionMethod($controller, '__invoke');
        $lines = file((string) $method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        expect($body)->toContain('$this->writes->write(');
    }
});

it('lists every v1 webhook controller that writes', function (): void {
    $writers = array_map(
        static fn (string $class): string => new ReflectionClass($class)->getShortName(),
        WEBHOOK_ENDPOINT_WRITES,
    );
    $readers = ['ListWebhookDeliveriesController', 'ListWebhookEndpointsController', 'ShowWebhookEndpointController'];

    $all = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src/Http/Controllers/Webhooks')->name('*.php') as $file) {
        $all[] = $file->getBasename('.php');
    }

    expect($all)->toEqualCanonicalizing([...$writers, ...$readers]);
});

arch('domain exceptions of the webhooks package implement the HTTP problem contract')
    ->expect('Vaulter\Webhooks\Exceptions')
    ->classes()
    ->toImplement('Vaulter\Exceptions\Contracts\ProblemException')
    // Raised inside the delivery job, which settles the attempt itself; no endpoint can answer with it.
    ->ignoring('Vaulter\Webhooks\Exceptions\WebhookResponseTooLargeException');

it('binds no route model in the package', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/routes'])->name('*.php') as $file) {
        if (preg_match('/Route::(bind|model)\s*\(/', $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('is not named by the kernel: no file of the core source references Vaulter\Webhooks', function (): void {
    $core = InstalledVersions::getInstallPath('axiomasoft/vaulter-core').'/src';
    $offenders = [];
    foreach ((new Finder)->files()->in($core)->name('*.php') as $file) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        if (preg_match('/\bVaulter\\\\Webhooks\\\\/', $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps no file of the package in the kernel namespace layout', function (): void {
    // PSR-4 prefixes overlap (`Vaulter\` of core, `Vaulter\Webhooks\` here): every type of this package lives under Vaulter\Webhooks.
    $offenders = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getPathname());
        if (preg_match('/^namespace (.+);$/m', $contents, $match) !== 1 || !str_starts_with($match[1], 'Vaulter\Webhooks')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
