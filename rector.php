<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\OptionalParametersAfterRequiredRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\ArrowFunction\StaticArrowFunctionRector;
use Rector\CodingStyle\Rector\Closure\StaticClosureRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\EarlyReturn\Rector\If_\RemoveAlwaysElseRector;
use Rector\EarlyReturn\Rector\Return_\PreparedValueToEarlyReturnRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddMethodCallBasedStrictParamTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationBasedOnParentClassMethodRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/core/src',
    ])
    ->withPhpSets(php84: true)
    ->withRules([
        // Type declarations
        AddVoidReturnTypeWhereNoReturnRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AddReturnTypeDeclarationBasedOnParentClassMethodRector::class,
        AddMethodCallBasedStrictParamTypeRector::class,
        TypedPropertyFromAssignsRector::class,

        // Dead code
        RemoveUnusedPrivateMethodRector::class,
        RemoveUnusedPrivatePropertyRector::class,

        // Early return
        RemoveAlwaysElseRector::class,
        PreparedValueToEarlyReturnRector::class,

        // Code quality
        OptionalParametersAfterRequiredRector::class,
        ExplicitBoolCompareRector::class,
        AddTypeToConstRector::class,

        // Coding style
        StaticClosureRector::class,
        StaticArrowFunctionRector::class,
    ]);
