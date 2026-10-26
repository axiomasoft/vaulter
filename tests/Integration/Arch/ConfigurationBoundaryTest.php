<?php

declare(strict_types=1);

use Vaulter\Integration\Tests\Support\ConfigurationArchitectureGuard;

arch('core reads configuration through typed package accessors (P2.5)')
    ->expect('Vaulter')
    ->not->toUse(['config'])
    ->ignoring(['Vaulter\Configuration', 'Vaulter\Drive\Configuration', 'Vaulter\Documents\Configuration', 'Vaulter\Media\Configuration', 'Vaulter\Filament\Configuration', 'Vaulter\Azgard\Configuration', 'Vaulter\Tests', 'Vaulter\Testing', 'Vaulter\Drive\Testing', 'Vaulter\Documents\Testing', 'Vaulter\Load', 'Vaulter\Drive\Tests', 'Vaulter\Documents\Tests', 'Vaulter\Media\Tests', 'Vaulter\Filament\Tests', 'Vaulter\Azgard\Tests']);

test('core source has no direct Vaulter configuration reads (P2.5)', function (): void {
    $package = __DIR__.'/../../../packages/core';
    $offenders = [];

    foreach (glob(dirname($package).'/*', GLOB_ONLYDIR) as $owner) {
        foreach (['src', 'database', 'routes'] as $scope) {
            $directory = $owner.'/'.$scope;

            if (!is_dir($directory)) {
                continue;
            }

            $files = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
                '/\\.php$/',
            );

            foreach ($files as $file) {
                if (str_contains($file->getPathname(), '/Configuration/') || str_contains($file->getPathname(), '/src/Testing/')) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                expect($contents)->not->toBeFalse();

                if (preg_match('/(?<!->)\\bconfig\\s*\\(\\s*[\'\"]vaulter/', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('all six packages enforce configuration boundaries in every PHP file (P2.5)', function (): void {
    // AST inventory also covers classless Pest/config/routes files and deleted
    // class names, which native class_exists-based architecture rules cannot see.
    $violations = (new ConfigurationArchitectureGuard)->scanPackages(__DIR__.'/../../../packages');

    expect($violations)->toBe([]);
});
