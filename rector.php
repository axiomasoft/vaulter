<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->disableParallel();
    $rectorConfig->paths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/core/database',
        __DIR__.'/packages/core/tests',
    ]);
    $rectorConfig->importNames(importDocBlockNames: false);
    $rectorConfig->removeUnusedImports();

    PackmanRectorSet::configure($rectorConfig, [
        SeparateMultiUseImportsRector::class,
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyAnonymousClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        ExplicitNullableObjectInstanceRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        NarrowObjectReturnTypeRector::class,
        NarrowWideUnionReturnTypeRector::class,
        ThrowWithPreviousExceptionRector::class,
        SimplifyBoolIdenticalTrueRector::class,
    ]);
};
