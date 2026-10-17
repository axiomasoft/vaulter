<?php

declare(strict_types=1);

arch('webhooks reads configuration through its typed accessor')
    ->expect('Vaulter\Webhooks')
    ->not->toUse(['config'])
    ->ignoring(['Vaulter\Webhooks\Configuration', 'Vaulter\Webhooks\Testing']);

test('webhooks source has no direct Vaulter configuration reads', function (): void {
    $package = dirname(__DIR__, 2);
    $offenders = [];

    foreach (['src', 'database', 'routes'] as $scope) {
        $directory = $package.'/'.$scope;
        if (!is_dir($directory)) {
            continue;
        }

        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            if (str_contains($file->getPathname(), '/Configuration/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/(?<!->)\bconfig\s*\(\s*[\'\"]vaulter/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('webhooks keys live under the vaulter-webhooks root only', function (): void {
    $offenders = [];
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file->getPathname());
        // `vaulter.webhooks.<key>` is a config key of the kernel root; `vaulter.webhooks.manage` is a Gate ability.
        if (preg_match('/[\'"`\s]vaulter\.(webhooks\.(?!manage\b(?!_))|models\.webhook_|schedule\.webhooks_)/', $contents) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
