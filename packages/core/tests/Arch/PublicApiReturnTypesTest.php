<?php

declare(strict_types=1);

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Vaulter\Contracts\Access\AccessOperations;
use Vaulter\Contracts\Activity\ActivityOperations;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Drive\FileOperations;
use Vaulter\Contracts\Drive\FolderOperations;
use Vaulter\Contracts\Drive\NodeOperations;
use Vaulter\Contracts\Drive\TrashOperations;
use Vaulter\Contracts\Sharing\ShareOperations;
use Vaulter\Operations\AccessOperations as CoreAccessOperations;
use Vaulter\Operations\ActivityOperations as CoreActivityOperations;
use Vaulter\Operations\ShareOperations as CoreShareOperations;

/**
 * PLAN2.P4.13 (D44): no public Operations/handle method returns or takes an Eloquent
 * model, builder, collection or paginator. A method marked @internal (the handle's
 * `model()` and the HTTP list paginators) is outside the contract and exempt.
 */
function forbiddenPublicType(string $type): bool
{
    foreach ([Model::class, Builder::class, Collection::class, AbstractPaginator::class, AbstractCursorPaginator::class, Paginator::class] as $forbidden) {
        if (is_a($type, $forbidden, true)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function leakedTypes(?ReflectionType $type): array
{
    if ($type === null) {
        return [];
    }
    $members = $type instanceof ReflectionNamedType ? [$type] : ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType ? $type->getTypes() : []);
    $leaked = [];
    foreach ($members as $member) {
        if ($member instanceof ReflectionNamedType && !$member->isBuiltin() && forbiddenPublicType($member->getName())) {
            $leaked[] = $member->getName();
        }
    }

    return $leaked;
}

/** @return list<string> */
function leaks(string $class): array
{
    $leaks = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || str_contains((string) $method->getDocComment(), '@internal')) {
            continue;
        }
        foreach (leakedTypes($method->getReturnType()) as $type) {
            $leaks[] = $class.'::'.$method->getName().'() returns '.$type;
        }
        foreach ($method->getParameters() as $parameter) {
            foreach (leakedTypes($parameter->getType()) as $type) {
                $leaks[] = $class.'::'.$method->getName().'($'.$parameter->getName().') takes '.$type;
            }
        }
    }

    return $leaks;
}

it('keeps Eloquent out of the public operation contracts', function (string $class): void {
    expect(leaks($class))->toBe([]);
})->with([
    NodeOperations::class,
    FolderOperations::class,
    FileOperations::class,
    TrashOperations::class,
    DriveHandle::class,
    AccessOperations::class,
    ShareOperations::class,
    ActivityOperations::class,
    CoreAccessOperations::class,
    CoreShareOperations::class,
    CoreActivityOperations::class,
]);

it('sees the guard: a contract with a model in its signature is reported', function (): void {
    $probe = new class
    {
        public function find(): Model
        {
            throw new LogicException;
        }
    };

    expect(leaks($probe::class))->not->toBe([]);
});
