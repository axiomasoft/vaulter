<?php

declare(strict_types=1);

arch('production source never imports testing helpers (D35)')
    ->expect('Vaulter\Azgard')
    ->not->toUse('Vaulter\Azgard\Testing')
    ->ignoring(['Vaulter\Azgard\Testing', 'Vaulter\Azgard\Tests']);

arch('bridge source depends only on core and itself (P2.4)')
    ->expect('Vaulter\Azgard')
    ->not->toUse([
        'Vaulter\Media',
        'Vaulter\Drive',
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

            if (!str_starts_with($name, 'Vaulter\\') || $name === 'Vaulter\\Azgard' || str_starts_with($name, 'Vaulter\\Azgard\\')) {
                continue;
            }

            $coreFile = dirname(__DIR__, 2).'/core/src/'.str_replace('\\', '/', substr($name, strlen('Vaulter\\'))).'.php';

            if (!is_file($coreFile)) {
                $offenders[] = $file->getPathname().': '.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
});
