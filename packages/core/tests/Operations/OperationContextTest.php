<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

uses(TestCase::class, RefreshDatabase::class);

it('holds no domain model and tags the system reason', function (): void {
    $context = OperationContext::system(ScopeRef::drive(7), TenantRef::none(), 'import');

    expect($context->scope->kind)->toBe('drive')
        ->and($context->scope->id)->toBe('7')
        ->and($context->tenant->isNone())->toBeTrue()
        ->and($context->systemReason)->toBe('import')
        ->and($context->causationId)->toBeNull()
        ->and($context->correlationId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    foreach ((new ReflectionClass(OperationContext::class))->getProperties() as $property) {
        expect((string) $property->getType())->not->toContain('Models');
    }
});

it('derives keyed and caused contexts without changing the original', function (): void {
    $context = OperationContext::for(Actor::system('import'), new ScopeRef('drive', '1'), TenantRef::id(5), 'corr-1');
    $keyed = $context->withIdempotencyKey('Key "A"')->withCausation('cause-1');

    expect($context->idempotencyKey)->toBeNull()
        ->and($context->causationId)->toBeNull()
        ->and($keyed->idempotencyKey)->toBe('Key "A"')
        ->and($keyed->causationId)->toBe('cause-1')
        ->and($keyed->tenant->id)->toBe('5')
        ->and($keyed->correlationId)->toBe('corr-1');
});

it('accepts idempotency keys of 1 to 255 bytes only', function (string $key, bool $valid): void {
    $context = OperationContext::system(ScopeRef::drive(1), TenantRef::none(), 'import');
    if (!$valid) {
        expect(fn () => $context->withIdempotencyKey($key))->toThrow(InvalidArgumentException::class);

        return;
    }
    expect($context->withIdempotencyKey($key)->idempotencyKey)->toBe($key);
})->with(['empty' => ['', false], 'one byte' => ['k', true], 'limit' => [str_repeat('k', 255), true], 'too long' => [str_repeat('k', 256), false], 'case kept' => [' Key ', true]]);

it('validates the causation id like the correlation id', function (): void {
    $context = OperationContext::system(ScopeRef::drive(1), TenantRef::none(), 'import');

    expect(fn () => $context->withCausation("cause\n"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $context->withCausation(''))->toThrow(InvalidArgumentException::class);
});

it('exposes telemetry without personal data or the idempotency key', function (): void {
    $user = AccessTestHelpers::user(4242);
    $context = OperationContext::for(Actor::user($user), ScopeRef::drive(9), TenantRef::id('tenant-secret'), 'corr-9', 'cause-9', 'secret-key');

    expect($context->telemetry())->toBe([
        'vaulter.correlation_id' => 'corr-9',
        'vaulter.scope.kind' => 'drive',
        'vaulter.tenant' => 'id',
        'vaulter.actor.kind' => 'user',
        'vaulter.causation_id' => 'cause-9',
    ]);
    $flat = implode('|', $context->telemetry());
    expect($flat)->not->toContain('4242')->not->toContain('tenant-secret')->not->toContain('secret-key');
});

it('rejects malformed scope and tenant references', function (): void {
    expect(fn () => new ScopeRef('Drive', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScopeRef('drive', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantRef::id(''))->toThrow(InvalidArgumentException::class)
        ->and(TenantRef::of(null)->equals(TenantRef::none()))->toBeTrue()
        ->and(TenantRef::of(3)->equals(TenantRef::id('3')))->toBeTrue();
});

it('binds a drive context to an operation scoped to that drive', function (): void {
    $drive = AccessTestHelpers::storage();
    $context = DriveContext::for(drive: $drive, actor: Actor::system('import'), correlationId: 'corr-d');

    expect($context->operation->scope->equals(ScopeRef::drive($drive->id)))->toBeTrue()
        ->and($context->operation->tenant->equals(TenantRef::of($drive->tenant_id)))->toBeTrue()
        ->and($context->actor)->toBe($context->operation->actor)
        ->and($context->correlationId)->toBe('corr-d')
        ->and($context->drive)->toBe($drive);

    $foreign = OperationContext::system(ScopeRef::drive('not-'.$drive->id), TenantRef::none(), 'import');
    expect(fn () => new DriveContext(operation: $foreign, drive: $drive))->toThrow(InvalidArgumentException::class);
});
