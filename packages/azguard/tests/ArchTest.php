<?php

declare(strict_types=1);

arch('production source never imports testing helpers (D35)')
    ->expect('Vaulter\AzGuard')
    ->not->toUse('Vaulter\AzGuard\Testing')
    ->ignoring(['Vaulter\AzGuard\Testing', 'Vaulter\AzGuard\Tests']);

arch('bridge source depends only on core and itself (P2.4)')
    ->expect('Vaulter\AzGuard')
    ->not->toUse([
        'Vaulter\Media',
        'Vaulter\Documents',
        'Vaulter\Filament',
        'Vaulter\Load',
    ]);

test('every Vaulter source reference belongs to this bridge or core', function (): void {
    $offenders = [];
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')),
        '/\.php$/',
    );

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toBeFalse();

        foreach (token_get_all($contents) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            if (!str_starts_with($name, 'Vaulter\\') || $name === 'Vaulter\\AzGuard' || str_starts_with($name, 'Vaulter\\AzGuard\\')) {
                continue;
            }

            $coreFile = dirname(__DIR__, 2).'/core/src/'.str_replace('\\', '/', substr($name, strlen('Vaulter\\'))).'.php';

            $driveFile = dirname(__DIR__, 2).'/drive/src/'.str_replace('\\', '/', substr($name, strlen('Vaulter\\Drive\\'))).'.php';

            if (!is_file($coreFile) && (!str_starts_with($name, 'Vaulter\\Drive\\') || !is_file($driveFile))) {
                $offenders[] = $file->getPathname().': '.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
});
