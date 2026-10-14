<?php

declare(strict_types=1);

arch('azgard reads configuration through typed package accessors (P2.5)')
    ->expect('Vaulter\Azgard')
    ->not->toUse(['config'])
    ->ignoring(['Vaulter\Azgard\Configuration']);

test('azgard source has no direct Vaulter configuration reads (P2.5)', function (): void {
    $package = dirname(__DIR__, 2);
    $offenders = [];

    foreach ([$package] as $owner) {
        foreach (['src', 'database', 'routes'] as $scope) {
            $directory = $owner.'/'.$scope;

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

                $contents = file_get_contents($file->getPathname());
                expect($contents)->not->toBeFalse();

                if (preg_match('/(?<!->)\bconfig\s*\(\s*[\'\"]vaulter/', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
