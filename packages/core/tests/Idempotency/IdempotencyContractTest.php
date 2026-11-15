<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Idempotency\IdempotencyDigest;
use Vaulter\Idempotency\IdempotencyOutcome;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\ConcurrencyPolicy;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\ModuleReadiness;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationClaim;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Operations\OperationPolicySource;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/*
 * The identity of a keyed write in the kernel, on a probe operation the test declares. The scope
 * tuple types the actor without a delimiter collision and separates tenant, scope, operation and key; the request
 * hash is canonical and keeps a missing field, an explicit null and the string "null" apart; a write without a key
 * claims nothing; a reused key with another request is 422 without the stored result; a replay is authorized now,
 * before the receipt is decoded, and answers the historical result, never the live state.
 */

uses(TestCase::class, RefreshDatabase::class);

/** A user actor of a given morph type and id, as Actor::user() reads it from an Eloquent model. */
function icUser(string $type, string $id): Actor
{
    $user = new class extends User
    {
        public $incrementing = false;

        public string $morph = '';

        protected $keyType = 'string';

        public function getMorphClass(): string
        {
            return $this->morph;
        }
    };
    $user->morph = $type;
    $user->setAttribute('id', $id);

    return Actor::user($user);
}

function icContext(Actor $actor, ?string $key, string $scope = 'D1', ?TenantRef $tenant = null, string $correlation = 'corr-1'): OperationContext
{
    return OperationContext::for($actor, ScopeRef::drive($scope), $tenant ?? TenantRef::none(), $correlation, idempotencyKey: $key);
}

/** A runner whose catalogue declares the probe operations of this test for the core module. */
function icRunner(): OperationRunner
{
    $source = new class implements OperationPolicySource
    {
        public function policies(): array
        {
            return [
                'core.probe.write' => new OperationPolicy('core.probe', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt),
                'core.probe.other' => new OperationPolicy('core.probe_other', ConcurrencyPolicy::Append, IdempotencyPolicy::Receipt),
            ];
        }
    };

    return new OperationRunner(app(Kernel::class), app(ModuleReadiness::class), app(VaulterDatabase::class), app(OperationClaim::class), new OperationCatalog(static fn (): array => ['core' => [$source]]));
}

/** A codec that counts its decodes and an authorizer that denies while asked to. */
function icProbe(): object
{
    return new class
    {
        public int $decodes = 0;

        public bool $deny = false;

        public ReceiptCodec $codec;

        public ReplayAuthorizer $authorizer;

        public function __construct()
        {
            $probe = $this;
            $this->codec = new class($probe) implements ReceiptCodec
            {
                public function __construct(private object $probe) {}

                public function kind(): string
                {
                    return 'test.probe';
                }

                public function version(): int
                {
                    return 1;
                }

                public function encode(mixed $result): array
                {
                    return ['value' => $result];
                }

                public function decode(array $body, int $version): mixed
                {
                    $this->probe->decodes++;

                    return $body['value'];
                }

                public function resource(mixed $result): ?ResourceRef
                {
                    return null;
                }
            };
            $this->authorizer = new class($probe) implements ReplayAuthorizer
            {
                public function __construct(private object $probe) {}

                public function scope(mixed $result): ?ResourceRef
                {
                    return new ResourceRef('drive', 'D1');
                }

                public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
                {
                    if ($this->probe->deny) {
                        throw new RuntimeException('denied now');
                    }
                }
            };
        }

        /** @param array<string, mixed> $fingerprint */
        public function write(array $fingerprint): IdempotentWrite
        {
            return new IdempotentWrite($this->codec, $this->authorizer, $fingerprint);
        }
    };
}

function icRun(OperationRunner $runner, OperationContext $context, Closure $attempt, ?IdempotentWrite $write, string $operation = 'core.probe.write'): mixed
{
    return $runner->write(new OperationSpec($operation, new ModuleId('core')), $context, $attempt, $write);
}

function icRows(): int
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->count();
}

it('keeps actors whose type or id holds the delimiter apart, in the tuple and in the scope digest', function (): void {
    $left = IdempotencyDigest::actor(new ActorRef('a|b', 'c'));
    $right = IdempotencyDigest::actor(new ActorRef('a', 'b|c'));
    $tuple = static fn (array $actor): array => [
        'tenant_kind' => 'none', 'tenant_id' => null, 'scope_kind' => 'drive', 'scope_id' => 'D1',
        ...$actor, 'operation' => 'core.probe.write', 'idempotency_key' => 'K',
    ];

    expect($left['actor_id'])->not->toBe($right['actor_id'])
        ->and(IdempotencyDigest::scope($tuple($left)))->not->toBe(IdempotencyDigest::scope($tuple($right)))
        ->and($left)->toBe(['actor_kind' => 'user', 'actor_id' => '3:a|b|c'])
        ->and($right)->toBe(['actor_kind' => 'user', 'actor_id' => '1:a|b|c'])
        // The same id under another type is another actor; the same type and id is one, whatever the PHP type of the id.
        ->and(IdempotencyDigest::actor(new ActorRef('users', '7')))->not->toBe(IdempotencyDigest::actor(new ActorRef('admins', '7')))
        ->and(IdempotencyDigest::actor(new ActorRef('users', 7)))->toBe(IdempotencyDigest::actor(new ActorRef('users', '7')))
        // A system actor has no id, and its reason does not make it another actor.
        ->and(IdempotencyDigest::actor(new ActorRef(ActorRef::SYSTEM_TYPE, null, 'import')))->toBe(['actor_kind' => 'system', 'actor_id' => null])
        ->and(IdempotencyDigest::actor(new ActorRef(ActorRef::SYSTEM_TYPE, null, 'repair')))->toBe(['actor_kind' => 'system', 'actor_id' => null]);
});

it('bounds a long actor id by its digest and keeps two long actors apart', function (): void {
    $long = IdempotencyDigest::actor(new ActorRef(str_repeat('t', 150).'|x', str_repeat('i', 60)));
    $other = IdempotencyDigest::actor(new ActorRef(str_repeat('t', 150), 'x|'.str_repeat('i', 60)));

    expect(strlen((string) $long['actor_id']))->toBeLessThanOrEqual(191)
        ->and((string) $long['actor_id'])->toStartWith('sha256:')
        ->and($long['actor_id'])->not->toBe($other['actor_id']);
});

it('separates tenant, scope, operation, key and actor kind, and leaves the correlation out of the tuple', function (): void {
    $actor = icUser('users', '7');
    $base = IdempotencyDigest::tuple(icContext($actor, 'K'), 'core.probe.write', 'K');
    $variants = [
        IdempotencyDigest::tuple(icContext($actor, 'K', tenant: TenantRef::id('T2')), 'core.probe.write', 'K'),
        IdempotencyDigest::tuple(icContext($actor, 'K', scope: 'D2'), 'core.probe.write', 'K'),
        IdempotencyDigest::tuple(icContext($actor, 'K'), 'core.probe.other', 'K'),
        IdempotencyDigest::tuple(icContext($actor, 'K2'), 'core.probe.write', 'K2'),
        IdempotencyDigest::tuple(OperationContext::system(ScopeRef::drive('D1'), TenantRef::none(), 'import'), 'core.probe.write', 'K'),
    ];

    foreach ($variants as $variant) {
        expect(IdempotencyDigest::scope($variant))->not->toBe(IdempotencyDigest::scope($base));
    }
    expect(IdempotencyDigest::tuple(icContext($actor, 'K', correlation: 'corr-other'), 'core.probe.write', 'K'))->toBe($base);
});

it('hashes the request canonically and keeps a missing field, an explicit null and the string "null" apart', function (): void {
    expect(IdempotencyDigest::request(['node' => 'N', 'name' => 'a', 'opts' => ['y' => 1, 'x' => 2]]))
        ->toBe(IdempotencyDigest::request(['opts' => ['x' => 2, 'y' => 1], 'name' => 'a', 'node' => 'N']));

    $missing = IdempotencyDigest::request(['node' => 'N']);
    $null = IdempotencyDigest::request(['node' => 'N', 'expected' => null]);
    $text = IdempotencyDigest::request(['node' => 'N', 'expected' => 'null']);

    expect(array_unique([$missing, $null, $text]))->toHaveCount(3)
        // A list keeps its order: it is a value, not a set of keys.
        ->and(IdempotencyDigest::request(['ids' => ['a', 'b']]))->not->toBe(IdempotencyDigest::request(['ids' => ['b', 'a']]));
});

it('runs a write without a key as an ordinary attempt that claims nothing', function (): void {
    $probe = icProbe();
    $runs = 0;
    $attempt = static function () use (&$runs): string {
        return 'run-'.++$runs;
    };

    expect(icRun(icRunner(), icContext(icUser('users', '7'), null), $attempt, $probe->write(['name' => 'a'])))->toBe('run-1')
        ->and(icRun(icRunner(), icContext(icUser('users', '7'), null), $attempt, $probe->write(['name' => 'a'])))->toBe('run-2')
        ->and(icRows())->toBe(0)
        // The key is opaque bytes: the string "null" is a key like any other, never the absence of one.
        ->and(icRun(icRunner(), icContext(icUser('users', '7'), 'null'), $attempt, $probe->write(['name' => 'a'])))->toBe('run-3')
        ->and(icRun(icRunner(), icContext(icUser('users', '7'), 'null'), $attempt, $probe->write(['name' => 'a'])))->toBe('run-3')
        ->and(icRows())->toBe(1);
});

it('gives a delimiter-colliding actor its own attempt, never the receipt of the other', function (): void {
    $probe = icProbe();
    $runs = 0;
    $attempt = static function () use (&$runs): string {
        return 'canary-'.++$runs;
    };

    expect(icRun(icRunner(), icContext(icUser('a|b', 'c'), 'K'), $attempt, $probe->write(['name' => 'a'])))->toBe('canary-1')
        ->and(icRun(icRunner(), icContext(icUser('a', 'b|c'), 'K'), $attempt, $probe->write(['name' => 'a'])))->toBe('canary-2')
        ->and(icRun(icRunner(), icContext(icUser('a|b', 'c'), 'K'), $attempt, $probe->write(['name' => 'a'])))->toBe('canary-1')
        ->and($probe->decodes)->toBe(1)
        ->and(icRows())->toBe(2);
});

it('refuses a reused key with another request as 422 idempotency_key_reused, without the stored result', function (): void {
    $probe = icProbe();
    $runs = 0;
    $actor = icUser('users', '7');
    icRun(icRunner(), icContext($actor, 'K'), static function () use (&$runs): string {
        $runs++;

        return 'canary-secret-result';
    }, $probe->write(['name' => 'a', 'expected' => 3]));

    try {
        icRun(icRunner(), icContext($actor, 'K'), static function () use (&$runs): string {
            $runs++;

            return 'second';
        }, $probe->write(['name' => 'a', 'expected' => 4]));
        $this->fail('A reused key with another request ran.');
    } catch (IdempotencyKeyReusedException $refusal) {
        expect($refusal->httpStatus())->toBe(422)
            ->and($refusal->problemCode())->toBe('idempotency_key_reused')
            ->and(json_encode([$refusal->getMessage(), $refusal->problemDetails()]))->not->toContain('canary');
    }

    expect($runs)->toBe(1)
        ->and($probe->decodes)->toBe(0)
        ->and(icRows())->toBe(1);
});

it('authorizes a replay now, before the receipt is decoded, and answers the historical result, not the live one', function (): void {
    $probe = icProbe();
    $actor = icUser('users', '7');
    $live = 'v1';
    $attempt = static function () use (&$live): string {
        return $live;
    };
    expect(icRun(icRunner(), icContext($actor, 'K'), $attempt, $probe->write(['name' => 'a'])))->toBe('v1');

    $live = 'v2';
    $probe->deny = true;
    expect(fn () => icRun(icRunner(), icContext($actor, 'K'), $attempt, $probe->write(['name' => 'a'])))->toThrow(RuntimeException::class, 'denied now');
    expect($probe->decodes)->toBe(0)
        ->and(app(IdempotencyOutcome::class)->replayed())->toBeFalse();

    $probe->deny = false;
    app()->forgetScopedInstances();
    expect(icRun(icRunner(), icContext($actor, 'K'), $attempt, $probe->write(['name' => 'a'])))->toBe('v1')
        ->and($probe->decodes)->toBe(1)
        ->and(app(IdempotencyOutcome::class)->replayed())->toBeTrue()
        ->and(icRows())->toBe(1);
});
