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
