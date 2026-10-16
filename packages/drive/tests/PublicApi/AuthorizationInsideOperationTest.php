<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Operations\DriveContext;
use Vaulter\ValueObjects\Access\Actor;

// R3 of the operation pipeline (P3.10, ADR-0028): a write authorizes exactly once, inside the
// attempt, so the decision sees the transaction of the mutation.

/** Transaction levels at which the permission driver was asked for an override (once per decision). */
final class AuthorizationLevels
{
    /** @var list<int>|null */
    public static ?array $levels = null;
}

/**
 * The transaction level of every authorization decision taken while $callback runs.
 *
 * @return list<int>
 */
function authorizationLevels(Closure $callback): array
{
    AuthorizationLevels::$levels = [];
    try {
        $callback();

        return AuthorizationLevels::$levels;
    } finally {
        AuthorizationLevels::$levels = null;
    }
}

beforeEach(function (): void {
    $recording = new class extends DatabasePermissionDriver
    {
        public function override(Authorizable $actor, AccessScope $scope): ?Effect
        {
            if (AuthorizationLevels::$levels !== null) {
                AuthorizationLevels::$levels[] = app(VaulterDatabase::class)->connection()->transactionLevel();
            }

            return parent::override($actor, $scope);
        }
    };
    app()->instance($recording::class, $recording);
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter.access.permission_drivers.database', $recording::class);
});

it('authorizes a rename once, inside the operation transaction', function (): void {
    $system = DriveTestHelpers::drive();
    $folder = $system->folders()->create('renamed');
    $user = DriveTestHelpers::actor($system, 81);
    $handle = Vaulter::drive($system->model())->actingAs($user);
    $base = app(VaulterDatabase::class)->connection()->transactionLevel();

    // One decision on its own, outside any operation: the reference count of decisions.
    $context = DriveContext::for(drive: $system->model(), actor: Actor::user($user), correlationId: 'reference');
    $reference = authorizationLevels(static fn () => app(OperationGuard::class)->authorize($context, Ability::Edit, DriveTestHelpers::model($folder), OperationKind::Mutation));
    $during = authorizationLevels(static fn () => $handle->nodes()->rename($folder, 'renamed-again'));

    expect($reference)->not->toBe([])
        ->and(count($during))->toBe(count($reference))
        ->and(array_filter($during, static fn (int $level): bool => $level <= $base))->toBe([]);
});

it('authorizes a folder creation inside the operation transaction', function (): void {
    $system = DriveTestHelpers::drive();
    $user = DriveTestHelpers::actor($system, 82);
    $handle = Vaulter::drive($system->model())->actingAs($user);
    $base = app(VaulterDatabase::class)->connection()->transactionLevel();

    $during = authorizationLevels(static fn () => $handle->folders()->create('inside'));

    expect($during)->not->toBe([])
        ->and(array_filter($during, static fn (int $level): bool => $level <= $base))->toBe([]);
});

it('authorizes an upload again inside the operation transaction, after the content is staged', function (): void {
    $system = DriveTestHelpers::drive();
    $user = DriveTestHelpers::actor($system, 83);
    $handle = Vaulter::drive($system->model())->actingAs($user);
    $base = app(VaulterDatabase::class)->connection()->transactionLevel();

    $during = authorizationLevels(static fn () => $handle->files()->upload(UploadSource::fromString('bytes', 'staged.txt')));

    // The decision before staging runs at the base level; the one that counts runs in the attempt.
    expect(array_filter($during, static fn (int $level): bool => $level > $base))->not->toBe([]);
});
