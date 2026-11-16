<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;

// Vaulter-filament is not a root composer dependency, so it cannot share
// the root rector.php run (Rector needs the package's OWN autoloader for type-aware
// rules) — package-local config, mirroring the package-local phpstan.neon/pint.json
// pattern already used for the other four satellites.
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
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
    ]);
