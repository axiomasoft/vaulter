<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Packman\DevTools\Rector\Rules\Boundaries\BoundarySafeStringClassRector;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\TypeDeclaration\Rector\ClassLike\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->disableParallel();
    $rectorConfig->paths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/core/database',
        __DIR__.'/packages/core/tests',
        __DIR__.'/packages/media/src',
        __DIR__.'/packages/media/database',
        __DIR__.'/packages/media/tests',
        __DIR__.'/packages/drive/src',
        __DIR__.'/packages/drive/database',
        __DIR__.'/packages/drive/tests',
        __DIR__.'/packages/documents/src',
        __DIR__.'/packages/documents/database',
        __DIR__.'/packages/documents/tests',
        __DIR__.'/packages/collaboration/src',
        __DIR__.'/packages/collaboration/database',
        __DIR__.'/packages/collaboration/tests',
        __DIR__.'/packages/webhooks/src',
        __DIR__.'/packages/webhooks/database',
        __DIR__.'/packages/webhooks/tests',
        __DIR__.'/packages/azguard/src',
        __DIR__.'/packages/azguard/tests',
        __DIR__.'/packages/filament/src',
        __DIR__.'/packages/filament/tests',
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
        // PluginManifest::resourceRoot rejects paths containing '..' segments.
        DirnameDirConcatStringToDirectStringPathRector::class,
    ]);

    $rectorConfig->ruleWithConfiguration(ClassPropertyAssignToConstructorPromotionRector::class, [
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ]);

    $rectorConfig->skip([
        '*/Fixtures/*',
        '*/Stubs/*',
        // AccessOptions intentionally keeps a string FQCN to avoid importing Vaulter\Drive\Testing\*.
        BoundarySafeStringClassRector::class => [
            __DIR__.'/packages/drive/src/Configuration/AccessOptions.php',
            __DIR__.'/packages/*/tests/Arch/*',
        ],
        // $counting is mutated inside DB::listen closure by reference.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__.'/packages/drive/tests/Feature/ExactListingTest.php',
        ],
        // Anonymous FilesystemAdapter test doubles intentionally throw unconditionally.
        AddVoidReturnTypeWhereNoReturnRector::class => [
            __DIR__.'/packages/drive/tests/Doctor/DiskDoctorChecksTest.php',
        ],
        // Pest binds $this to dataset closures, which throws on static first-class callables.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/packages/*/tests/*',
        ],
    ]);
};
