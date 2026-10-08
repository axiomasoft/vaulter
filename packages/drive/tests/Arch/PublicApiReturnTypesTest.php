<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Operations\FileOperations;
use Vaulter\Drive\Operations\FolderOperations;
use Vaulter\Drive\Operations\NodeOperations;
use Vaulter\Drive\Operations\TrashOperations;

/**
 * PLAN2.P4.13 (D44): the drive implementations of the public operations never return
 * or take an Eloquent model, builder, collection or paginator (methods marked @internal
 * are exempt).
 */
it('keeps Eloquent out of the drive operations and handle', function (string $class): void {
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
                    foreach ($forbidden as $class_) {
                        if (is_a($member->getName(), $class_, true)) {
                            $leaks[] = $method->class.'::'.$method->getName().'() uses '.$member->getName();
                        }
                    }
                }
            }
        }
    }

    expect($leaks)->toBe([]);
})->with([DriveHandle::class, NodeOperations::class, FolderOperations::class, FileOperations::class, TrashOperations::class]);
