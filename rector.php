<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/core/database',
        __DIR__.'/packages/core/tests',
        // F-P4.4-01: root vendor already autoloads these three satellites (root
        // composer.json `require`s them as path-repos for the core test fixtures),
        // so their src is reachable here without a separate install. vaulter-filament
        // (and vaulter-azguard, excluded from this batch's five-package
        // scope) is NOT a root dependency — it gets its own package-local rector.php
        // (packages/filament/rector.php) run from its own CI job instead.
        __DIR__.'/packages/drive/src',
        __DIR__.'/packages/media/src',
        __DIR__.'/packages/documents/src',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSkip([
        // Pint owns import ordering / formatting; avoid Rector fighting it.
        SeparateMultiUseImportsRector::class,
        // Keep readable "=== null" guards instead of "! $x instanceof Foo".
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
