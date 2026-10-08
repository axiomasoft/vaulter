<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;

/**
 * PLAN2.P4.8 (D44, P4-I23): the documents operations never return or take an Eloquent
 * model, builder, collection or paginator (methods marked @internal are exempt).
 */
it('keeps Eloquent out of the documents operations', function (string $class): void {
    $leaks = [];
    $forbidden = [Model::class, Builder::class, Collection::class, AbstractPaginator::class, AbstractCursorPaginator::class];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || str_contains((string) $method->getDocComment(), '@internal')) {
            continue;
        }
        $types = [$method->getReturnType(), ...array_map(static fn (ReflectionParameter $parameter): ?ReflectionType => $parameter->getType(), $method->getParameters())];
        foreach ($types as $type) {
            $members = $type instanceof ReflectionNamedType ? [$type] : ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : []);
            foreach ($members as $member) {
                if ($member instanceof ReflectionNamedType && !$member->isBuiltin()) {
                    foreach ($forbidden as $forbiddenClass) {
                        if (is_a($member->getName(), $forbiddenClass, true)) {
                            $leaks[] = $method->class.'::'.$method->getName().'() uses '.$member->getName();
                        }
                    }
                }
            }
        }
    }

    expect($leaks)->toBe([]);
})->with([DocumentOperations::class, CommentOperations::class, DocumentsManager::class]);

it('keeps every public documents operation typed', function (string $class): void {
    $untyped = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor()) {
            continue;
        }
        if (!$method->hasReturnType()) {
            $untyped[] = $method->getName().'() return';
        }
        foreach ($method->getParameters() as $parameter) {
            if (!$parameter->hasType()) {
                $untyped[] = $method->getName().'() $'.$parameter->getName();
            }
        }
    }

    expect($untyped)->toBe([]);
})->with([DocumentOperations::class, CommentOperations::class]);
