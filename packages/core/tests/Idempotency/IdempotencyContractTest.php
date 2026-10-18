<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Nodes\StaleNodeException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Idempotency\IdempotencyDigest;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Idempotency\ReplayedProblemException;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\Tests\Idempotency\Fixtures\EchoReceiptCodec;
use Vaulter\Tests\Idempotency\Fixtures\RecordingReplayAuthorizer;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * I11 (D25, ADR-0029, knowledge/p3-operations#idempotency): a keyed write claims its key inside the root
 * transaction, stores its receipt with the mutation, replays it only after the owner's current authorization,
 * caches a deterministic refusal through a savepoint and never an auth, visibility, validation or precondition
 * refusal. Committed migrations, not a wrapping transaction: the root transaction and the savepoint are only
 * honest at transaction level 0.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(fn () => config()->set('vaulter-drive.access.permission_driver', 'database'));

function idemRows(): int
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->count();
}

function idemRow(): ?stdClass
{
    /** @var stdClass|null $row */
    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->first();

    return $row;
}

/** Whether `$write` is refused like a new request of an actor without the right (not found or denied). */
function idemDenied(Closure $write): bool
{
    try {
        $write();
    } catch (NodeNotFoundException|PermissionDeniedException) {
        return true;
    }

    return false;
}

function idemActivity(): int
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table('activity'))->count();
}

/** A drive whose user 1 may write below the root. @return array{Drive, Node} */
function idemDrive(): array
{
    $drive = AccessTestHelpers::storage();
    $root = Node::query()->findOrFail($drive->root_node_id);
    AccessTestHelpers::grant($root, RoleRegistry::WRITER);

    return [$drive, $root];
}

function idemHandle(Drive $drive, string $key, int $user = 1): DriveHandle
{
    return Vaulter::drive($drive)->actingAs(AccessTestHelpers::user($user))->withIdempotencyKey($key);
}

/**
 * A keyed write of the runner itself with the echo codec.
 *
 * @param Closure(): string $attempt
 */
function idemWrite(OperationContext $context, Closure $attempt, ?RecordingReplayAuthorizer $authorizer = null, array $fingerprint = ['input' => 'same']): string
{
    return app(OperationRunner::class)->write(
        new OperationSpec('drive.folders.create', new ModuleId('drive')),
        $context,
        static fn (): string => $attempt(),
        new IdempotentWrite(new EchoReceiptCodec, $authorizer ?? new RecordingReplayAuthorizer, $fingerprint),
    );
}

it('replays the stored snapshot for the same key and request, without a second effect', function (): void {
    [$drive] = idemDrive();

    $first = idemHandle($drive, 'create-1')->folders()->create('Reports');
    $activity = idemActivity();
    $second = idemHandle($drive, 'create-1')->folders()->create('Reports');

    expect($second)->toEqual($first)
        ->and(Node::query()->where('name', 'Reports')->count())->toBe(1)
        ->and(idemActivity())->toBe($activity)
        ->and(idemRows())->toBe(1)
        ->and(idemRow())->status->toBe(IdempotencyStore::COMPLETED)
        ->and(idemRow())->receipt_kind->toBe(NodeSnapshotCodec::KIND)
        ->and(idemRow())->resource_id->toBe($first->id);
});

it('refuses the same key with another request and runs nothing', function (): void {
    [$drive] = idemDrive();
    idemHandle($drive, 'create-2')->folders()->create('Alpha');

    expect(fn () => idemHandle($drive, 'create-2')->folders()->create('Beta'))->toThrow(IdempotencyKeyReusedException::class);

    expect(Node::query()->where('name', 'Beta')->exists())->toBeFalse()
        ->and(idemRows())->toBe(1);
});

it('compares keys as exact bytes: never trimmed or case-folded', function (string $other): void {
    [$drive] = idemDrive();
    idemHandle($drive, 'Key-A')->folders()->create('Same');

    // Not a replay: the operation runs and meets the folder of the first key.
    expect(fn () => idemHandle($drive, $other)->folders()->create('Same'))->toThrow(NodeNameConflictException::class);
})->with(['lower case' => 'key-a', 'padded' => ' Key-A', 'trailing' => 'Key-A ']);

it('caches a deterministic refusal through a savepoint that undoes the attempt and its events', function (): void {
    [$drive, $root] = idemDrive();
    $context = DriveContext::for($drive, Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->actor(), 'corr-1', 'refusal-1');
    $activity = idemActivity();
    $runs = 0;
    $attempt = function () use ($context, $root, &$runs): string {
        $runs++;
        app(CreateFolderAction::class)->execute($context, new CreateFolderInput($context->drive, 'Undone', $root));

        throw NodeNameConflictException::forName('Undone', $root->id);
    };

    expect(fn () => idemWrite($context->operation, $attempt))->toThrow(NodeNameConflictException::class);
    expect(Node::query()->where('name', 'Undone')->exists())->toBeFalse()
        ->and(idemActivity())->toBe($activity)
        ->and(idemRow())->receipt_kind->toBe('vaulter.problem')
        ->and(idemRow())->http_code->toBe(409);

    try {
        idemWrite($context->operation, $attempt);
        $this->fail('The cached refusal was not replayed.');
    } catch (ReplayedProblemException $replayed) {
        expect($replayed->problemCode())->toBe('node_name_conflict')
            ->and($replayed->httpStatus())->toBe(409)
            ->and($runs)->toBe(1);
    }
});

it('caches a stale precondition of a rename and replays it', function (): void {
    [$drive] = idemDrive();
    $folder = Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->folders()->create('Old');

    expect(fn () => idemHandle($drive, 'rename-1')->nodes()->rename($folder->id, 'New', $folder->sequence + 5))->toThrow(StaleNodeException::class);
    expect(fn () => idemHandle($drive, 'rename-1')->nodes()->rename($folder->id, 'New', $folder->sequence + 5))
        ->toThrow(ReplayedProblemException::class);
    expect(Node::query()->findOrFail($folder->id)->name)->toBe('Old')
        ->and(idemRow())->receipt_kind->toBe('vaulter.problem');
});

it('never caches an auth, visibility, validation or precondition refusal', function (Closure $refusal): void {
    [$drive] = idemDrive();
    $context = DriveContext::for($drive, Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->actor(), 'corr-2', 'not-cached');
    $runs = 0;
    $attempt = function () use (&$runs, $refusal): string {
        $runs++;

        throw $refusal();
    };

    foreach ([1, 2] as $round) {
        try {
            idemWrite($context->operation, $attempt);
        } catch (Throwable) {
            // the refusal itself
        }
        expect(idemRows())->toBe(0)->and($runs)->toBe($round);
    }
})->with([
    '403' => [static fn (): Throwable => PermissionDeniedException::forAbility('create')],
    '404' => [static fn (): Throwable => NodeNotFoundException::forId('hidden')],
    '422 validation' => [static fn (): Throwable => ValidationException::withMessages(['name' => ['bad']])],
    '428' => [static fn (): Throwable => PreconditionRequiredException::missing('If-Match')],
    '500' => [static fn (): Throwable => new RuntimeException('boom')],
]);

it('answers a replay after the grant was revoked with the current denial and keeps the receipt', function (): void {
    $drive = AccessTestHelpers::storage();
    $root = Node::query()->findOrFail($drive->root_node_id);
    $grant = AccessTestHelpers::grant($root, RoleRegistry::WRITER);
    idemHandle($drive, 'revoked')->folders()->create('Granted');
    $receipt = idemRow();

    app(VaulterDatabase::class)->write(static fn () => $grant->delete());

    expect(idemDenied(fn () => idemHandle($drive, 'revoked')->folders()->create('Granted')))->toBeTrue()
        ->and(idemRow())->toEqual($receipt);
});

it('authorizes a replay where the node is now, not where it was written', function (): void {
    $drive = AccessTestHelpers::storage();
    $shared = AccessTestHelpers::folder($drive, null, 'Shared');
    $hidden = AccessTestHelpers::folder($drive, null, 'Hidden');
    AccessTestHelpers::grant($shared, RoleRegistry::WRITER, GrantScope::Subtree);

    $created = idemHandle($drive, 'moved')->folders()->create('Plan', $shared->id);
    Vaulter::drive($drive)->asSystem('test')->nodes()->move($created->id, $hidden->id);

    expect(idemDenied(fn () => idemHandle($drive, 'moved')->folders()->create('Plan', $shared->id)))->toBeTrue();
});

it('falls back to the recorded scope when the node is gone, and denies once that scope is closed', function (): void {
    $drive = AccessTestHelpers::storage();
    $shared = AccessTestHelpers::folder($drive, null, 'Team');
    $grant = AccessTestHelpers::grant($shared, RoleRegistry::WRITER, GrantScope::Subtree);

    $created = idemHandle($drive, 'gone')->folders()->create('Draft', $shared->id);
    Vaulter::drive($drive)->asSystem('test')->nodes()->trash($created->id);

    expect(idemHandle($drive, 'gone')->folders()->create('Draft', $shared->id))->toEqual($created)
        ->and(idemRow())->auth_scope_kind->toBe('node')
        ->and(idemRow())->auth_scope_id->toBe((string) $shared->id);

    app(VaulterDatabase::class)->write(static fn () => $grant->delete());
    expect(idemDenied(fn () => idemHandle($drive, 'gone')->folders()->create('Draft', $shared->id)))->toBeTrue();
});

it('scopes a system actor and a drive without tenant by tagged values, not nulls', function (): void {
    [$drive] = idemDrive();
    $system = Vaulter::drive($drive)->asSystem('import')->withIdempotencyKey('sys-1');

    $first = $system->folders()->create('Imported');
    // Another reason is the same system actor: a replay.
    expect(Vaulter::drive($drive)->asSystem('repair')->withIdempotencyKey('sys-1')->folders()->create('Imported'))->toEqual($first);
    // A user with the same key is another scope: the operation runs.
    expect(fn () => idemHandle($drive, 'sys-1')->folders()->create('Imported'))->toThrow(NodeNameConflictException::class);

    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->where('actor_kind', 'system')->first();
    expect(idemRows())->toBe(2)
        ->and($row->actor_kind)->toBe('system')
        ->and($row->actor_id)->toBeNull()
        ->and($row->tenant_kind)->toBe(TenantRef::NONE)
        ->and($row->tenant_id)->toBeNull()
        ->and(strlen((string) $row->scope_digest))->toBe(64);
});

it('treats an expired receipt as absent and prunes only expired receipts', function (): void {
    [$drive] = idemDrive();
    idemHandle($drive, 'ttl')->folders()->create('Once');

    $this->travel(86401)->seconds();
    expect(fn () => idemHandle($drive, 'ttl')->folders()->create('Once'))->toThrow(NodeNameConflictException::class);

    idemHandle($drive, 'fresh')->folders()->create('Fresh');
    $this->travel(86401)->seconds();
    idemHandle($drive, 'newest')->folders()->create('Newest');

    $this->artisan('vaulter:idempotency:prune')->assertExitCode(0);
    expect(idemRows())->toBe(1);
    Carbon::setTestNow();
});

it('refuses an unknown codec version instead of guessing', function (): void {
    [$drive] = idemDrive();
    idemHandle($drive, 'versioned')->folders()->create('Versioned');
    app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->update(['codec_version' => 2]);

    expect(fn () => idemHandle($drive, 'versioned')->folders()->create('Versioned'))->toThrow(LogicException::class, 'does not know version 2');
    expect(fn () => (new NodeSnapshotCodec)->decode([], 3))->toThrow(LogicException::class);
});

it('refuses a key on a write that does not declare idempotency, before it runs', function (): void {
    [$drive] = idemDrive();
    $folder = Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->folders()->create('Stay');

    expect(fn () => idemHandle($drive, 'star-1')->nodes()->star($folder->id))->toThrow(InvalidArgumentException::class, 'does not accept an idempotency key');
    expect(Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->nodes()->find($folder->id)->viewer?->starred)->toBeFalse();
});

it('gives a composed write the one receipt of its root', function (): void {
    [$drive, $root] = idemDrive();
    $handle = idemHandle($drive, 'outer');
    $context = DriveContext::for($drive, $handle->actor(), 'corr-3', 'outer');

    $result = idemWrite($context->operation, static function () use ($handle): string {
        return $handle->folders()->create('Inner')->id;
    });

    expect(idemRows())->toBe(1)
        ->and(idemRow())->receipt_kind->toBe('test.echo')
        ->and(Node::query()->whereKey($result)->exists())->toBeTrue();
});

it('replays only after the authorizer and passes it the recorded refs', function (): void {
    [$drive] = idemDrive();
    $context = DriveContext::for($drive, Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->actor(), 'corr-4', 'auth-1');
    idemWrite($context->operation, static fn (): string => 'done');

    $authorizer = new RecordingReplayAuthorizer(PermissionDeniedException::forAbility('create'));
    expect(fn () => idemWrite($context->operation, static fn (): string => 'again', $authorizer))->toThrow(PermissionDeniedException::class);
    expect($authorizer->calls)->toHaveCount(1)
        ->and($authorizer->calls[0][0])->toBeNull()
        ->and($authorizer->calls[0][1]?->id)->toBe('scope-of-test');

    $allow = new RecordingReplayAuthorizer;
    expect(idemWrite($context->operation, static fn (): string => 'again', $allow))->toBe('done');
});

it('lets a transport fingerprint replace the operation fingerprint', function (): void {
    [$drive] = idemDrive();
    $actor = Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->actor();
    $http = DriveContext::for($drive, $actor, 'corr-5', 'transport', 'fingerprint-a');
    idemWrite($http->operation, static fn (): string => 'first', fingerprint: ['input' => 'one']);

    // Same transport fingerprint, another operation fingerprint (a stale precondition re-derived): a replay.
    expect(idemWrite($http->operation, static fn (): string => 'second', fingerprint: ['input' => 'two']))->toBe('first');
    $other = DriveContext::for($drive, $actor, 'corr-6', 'transport', 'fingerprint-b');
    expect(fn () => idemWrite($other->operation, static fn (): string => 'third'))->toThrow(IdempotencyKeyReusedException::class);
});

it('encodes the scope tuple unambiguously and the request canonically', function (): void {
    $tuple = static fn (string $scope, string $op): array => [
        'tenant_kind' => 'none', 'tenant_id' => null, 'scope_kind' => 'drive', 'scope_id' => $scope,
        'actor_kind' => 'user', 'actor_id' => 'u|1', 'operation' => $op, 'idempotency_key' => 'k',
    ];

    expect(IdempotencyDigest::scope($tuple('ab', 'c.d')))->not->toBe(IdempotencyDigest::scope($tuple('a', 'bc.d')))
        ->and(IdempotencyDigest::request(['b' => 1, 'a' => ['y' => 2, 'x' => 'é/']]))->toBe(IdempotencyDigest::request(['a' => ['x' => 'é/', 'y' => 2], 'b' => 1]))
        ->and(IdempotencyDigest::canonicalJson(['b' => 1, 'a' => 'é/']))->toBe('{"a":"é/","b":1}')
        ->and(fn () => IdempotencyDigest::request(['o' => new stdClass]))->toThrow(InvalidArgumentException::class);
});

it('parses one Structured Field String or one plain token, nothing else', function (array $values, ?string $key): void {
    if ($key === null) {
        expect(fn () => ReadIdempotencyKey::parse($values))->toThrow(ValidationException::class);

        return;
    }
    expect(ReadIdempotencyKey::parse($values))->toBe($key);
})->with([
    'sf string' => [['"abc-123"'], 'abc-123'],
    'sf escapes' => [['"a\\"b\\\\c"'], 'a"b\\c'],
    'sf spaces inside' => [['"a b"'], 'a b'],
    'plain token' => [['8e03978e-40d5-43e8-bc93-6894a57f9324'], '8e03978e-40d5-43e8-bc93-6894a57f9324'],
    'case kept' => [['AbC'], 'AbC'],
    'two headers' => [['a', 'b'], null],
    'sf list' => [['"a", "b"'], null],
    'plain list' => [['a,b'], null],
    'plain space' => [['a b'], null],
    'control' => [["a\x01"], null],
    'unterminated' => [['"abc'], null],
    'bad escape' => [['"a\\n"'], null],
    'empty sf' => [['""'], null],
    'empty' => [[''], null],
    'too long' => [[str_repeat('k', 256)], null],
]);

it('fingerprints a keyed HTTP request with an HMAC under the application key, the destination precondition included', function (): void {
    $fingerprint = static function (array $headers = []): string {
        $request = Request::create('/api/vaulter/v1/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"password":"hunter2"}');
        $request->headers->add(['Idempotency-Key' => 'k', ...$headers]);
        app(ReadIdempotencyKey::class)->handle($request, static fn (): Response => new Response);

        return ReadIdempotencyKey::requestFingerprint($request) ?? '';
    };
    // What a plain digest of the same request would be: a guess of the secret in the body could be tested against it.
    $plain = IdempotencyDigest::request(['method' => 'POST', 'route' => 'api/vaulter/v1/x', 'parameters' => [], 'query' => [], 'body' => ['password' => 'hunter2'], 'if_match' => null, 'destination_if_match' => null]);
    $first = $fingerprint();

    expect($first)->toMatch('/^[0-9a-f]{64}$/')->not->toBe($plain)
        ->and($fingerprint())->toBe($first)
        ->and($fingerprint(['X-Vaulter-Destination-If-Match' => '"node.1.2"']))->not->toBe($first);

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    app()->forgetInstance('encrypter');
    expect($fingerprint())->not->toBe($first);
});

it('refuses a request fingerprint without a key and a key out of bounds', function (): void {
    $scope = ScopeRef::drive('1');

    expect(fn () => OperationContext::system($scope, TenantRef::none(), 'test')->withIdempotencyKey(str_repeat('k', 256)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationContext(actor: Actor::system('test'), scope: $scope, tenant: TenantRef::none(), correlationId: 'c', requestFingerprint: 'fp'))->toThrow(InvalidArgumentException::class)
        ->and(OperationContext::system($scope, TenantRef::none(), 'test')->withIdempotencyKey('k', 'fp')->telemetry())->not->toHaveKey('vaulter.idempotency_key');
});

it('decodes the snapshot it encoded', function (): void {
    [$drive] = idemDrive();
    $snapshot = Vaulter::drive($drive)->actingAs(AccessTestHelpers::user())->folders()->create('Codec');
    $codec = new NodeSnapshotCodec;

    expect($codec->decode($codec->encode($snapshot), 1))->toEqual($snapshot)
        ->and($codec->resource($snapshot)->id)->toBe($snapshot->id)
        ->and(fn () => $codec->encode('not a snapshot'))->toThrow(InvalidArgumentException::class);
});

it('reads SQLITE_BUSY and the lock timeouts of the other engines as a lock timeout, and nothing else', function (): void {
    $isLockTimeout = new ReflectionMethod(IdempotencyStore::class, 'isLockTimeout');
    $exception = static fn (string $message, array $info): QueryException => (function () use ($message, $info): QueryException {
        $previous = new PDOException($message);
        $previous->errorInfo = $info;
        $query = new QueryException('default', 'insert', [], $previous);

        return $query;
    })();

    expect($isLockTimeout->invoke(null, $exception('database is locked', ['HY000', 5, 'database is locked'])))->toBeTrue()
        ->and($isLockTimeout->invoke(null, $exception('lock timeout', ['55P03', 7, 'lock timeout'])))->toBeTrue()
        ->and($isLockTimeout->invoke(null, $exception('Lock wait timeout', ['HY000', 1205, 'Lock wait timeout'])))->toBeTrue()
        ->and($isLockTimeout->invoke(null, $exception('constraint', ['23000', 19, 'UNIQUE constraint failed'])))->toBeFalse()
        ->and($isLockTimeout->invoke(null, $exception('table is locked', ['HY000', 5, 'database table is locked: x'])))->toBeFalse();
});

it('bounds the SQLite wait of a claim to two seconds and puts the previous busy timeout back', function (): void {
    $connection = app(VaulterDatabase::class)->connection();
    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The busy timeout is a SQLite setting.');
    }
    $before = $connection->selectOne('select timeout as v from pragma_busy_timeout')->v;

    [$drive] = idemDrive();
    idemHandle($drive, 'busy-1')->folders()->create('Busy');

    expect($connection->selectOne('select timeout as v from pragma_busy_timeout')->v)->toBe($before);
});
