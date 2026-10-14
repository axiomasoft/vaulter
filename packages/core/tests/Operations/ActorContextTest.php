<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

uses(TestCase::class, RefreshDatabase::class);

it('captures Eloquent morph identity immutably and serializes only ActorRef', function (): void {
    Relation::morphMap(['test-user' => TestUser::class]);
    try {
        $user = AccessTestHelpers::user(42);
        $actor = Actor::user($user);
        expect($actor->isSystem())->toBeFalse()
            ->and($actor->authorizable())->toBe($user)
            ->and($actor->ref()->type)->toBe('test-user')
            ->and($actor->ref()->id)->toBe(42)
            ->and(unserialize(serialize($actor->ref())) == $actor->ref())->toBeTrue();
        $user->id = 99;
        expect(fn () => $actor->authorizable())->toThrow(InvalidArgumentException::class);
        expect(fn () => serialize($actor))->toThrow(LogicException::class);
    } finally {
        Relation::morphMap(map: [], merge: false);
    }
});

it('captures non-Eloquent Authenticatable identity', function (): void {
    $user = new class(['id' => 'external-user']) extends GenericUser implements Authorizable
    {
        public function can($abilities, $arguments = [])
        {
            return true;
        }
    };
    $actor = Actor::user($user);
    expect($actor->ref()->id)->toBe('external-user')->and($actor->ref()->type)->toBe($user::class);
});

it('rejects authorizable-only and identity-less users rather than making system actors', function (): void {
    $user = Mockery::mock(Authorizable::class);
    expect(fn () => Actor::user($user))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Actor::user(new TestUser))->toThrow(InvalidArgumentException::class);
});

it('requires a trimmed nonempty system reason bounded in bytes', function (string $reason, bool $valid): void {
    if (!$valid) {
        expect(fn () => Actor::system($reason))->toThrow(InvalidArgumentException::class);

        return;
    }
    $actor = Actor::system($reason);
    expect($actor->isSystem())->toBeTrue()->and($actor->authorizable())->toBeNull()
        ->and($actor->ref()->type)->toBe(ActorRef::SYSTEM_TYPE)->and($actor->ref()->id)->toBeNull()
        ->and($actor->ref()->reason)->toBe(trim($reason));
})->with(['blank' => ['  ', false], 'trimmed' => [' nightly import ', true], 'limit' => [str_repeat('x', 191), true], 'too long' => [str_repeat('x', 192), false], 'byte limit' => [str_repeat('я', 96), false]]);

it('rejects inconsistent system references', function (): void {
    expect(fn () => new ActorRef(type: ActorRef::SYSTEM_TYPE, id: 1, reason: 'import'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ActorRef(type: ActorRef::SYSTEM_TYPE, id: null))->toThrow(InvalidArgumentException::class);
});

it('creates system context with ULID fallback and stable drive identity', function (): void {
    $drive = AccessTestHelpers::storage();
    $context = DriveContext::system(drive: $drive, reason: 'import');
    $key = $drive->id;
    $drive->id = 'forged';
    expect($context->correlationId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($context->driveId())->toBe($key)
        ->and($context->actor->ref()->reason)->toBe('import');
    expect(fn () => DriveContext::system(drive: new Drive, reason: 'import'))->toThrow(InvalidArgumentException::class);
});

it('validates correlation identifiers and retains idempotency keys', function (string $id, bool $valid): void {
    $drive = AccessTestHelpers::storage();
    if (!$valid) {
        expect(fn () => DriveContext::for(drive: $drive, actor: Actor::system('import'), correlationId: $id))->toThrow(InvalidArgumentException::class);

        return;
    }
    $context = DriveContext::for(drive: $drive, actor: Actor::system('import'), correlationId: $id, idempotencyKey: 'replay-1');
    expect($context->correlationId)->toBe($id)->and($context->idempotencyKey)->toBe('replay-1');
})->with(['empty' => ['', false], 'blank' => ['  ', false], 'newline' => ["req\n1", false], 'DEL' => ["req\x7f", false], 'long' => [str_repeat('x', 192), false], 'valid' => ['job-123', true], 'limit' => [str_repeat('x', 191), true]]);
