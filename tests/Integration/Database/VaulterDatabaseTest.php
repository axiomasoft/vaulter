<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Integration\Tests\ObservabilityDocuments\P83DocumentsTestCase;
use Vaulter\Testing\Concurrency\ForkRace;

uses(P83DocumentsTestCase::class);

afterEach(function (): void {
    config()->set('vaulter.database.connection', null);
});

it('resolves the same dedicated connection as Vaulter models', function (string $modelClass): void {
    config()->set('database.connections.vaulter', config('database.connections.testing'));
    config()->set('vaulter.database.connection', 'vaulter');

    $database = app(VaulterDatabase::class);

    expect($database)->toBe(app(VaulterDatabase::class))
        ->and($database->connection())->toBe((new $modelClass)->getConnection())
        ->and($database->driver())->toBe($database->connection()->getDriverName());
})->with([
    Node::class,
    UploadSession::class,
    UploadPart::class,
    NodeComment::class,
    DocumentRevision::class,
]);

it('refuses locks without a transaction on its connection', function (string $method): void {
    app(VaulterDatabase::class)->{$method}('outbox', '1');
})->with(['lockAggregate', 'lockAggregateShared', 'advisoryLock'])->throws(LogicException::class);

it('retries serialization failures up to the configured attempts', function (): void {
    $attempts = 0;

    $result = app(VaulterDatabase::class)->transaction(function () use (&$attempts): int {
        if (++$attempts < 3) {
            throw new QueryException('testing', 'select 1', [], new PDOException('serialization failure', 40001));
        }

        return $attempts;
    });

    expect($result)->toBe(3)->and($attempts)->toBe(3);
});

it('runs after-commit callbacks only when its outer transaction commits', function (): void {
    $database = app(VaulterDatabase::class);
    $called = false;

    $database->transaction(function () use ($database, &$called): void {
        $database->transaction(function () use ($database, &$called): void {
            $database->afterCommit(function () use (&$called): void {
                $called = true;
            });
        });

        expect($called)->toBeFalse();
    });

    expect($called)->toBeTrue();
});

it('rolls back uploads and document model inserts on the dedicated connection', function (string $modelClass): void {
    ForkRace::skipUnlessSupported($this);
    $storage = Drive::forceCreate([
        'owner_type' => 'team', 'owner_id' => 1, 'name' => 'test', 'disk' => 'local',
    ]);
    $node = Node::forceCreate([
        'drive_id' => $storage->getKey(), 'name' => 'fixture', 'kind' => 'folder',
    ]);
    $session = UploadSession::forceCreate([
        'drive_id' => $storage->getKey(), 'upload_id' => (string) Str::uuid(),
        'protocol' => 'tus', 'status' => 'initiated', 'total_size_bytes' => 1,
    ]);
    config()->set('database.connections.vaulter', config('database.connections.testing'));
    config()->set('vaulter.database.connection', 'vaulter');

    $attributes = match ($modelClass) {
        UploadSession::class => [
            'drive_id' => $storage->getKey(), 'upload_id' => (string) Str::uuid(),
            'protocol' => 'tus', 'status' => 'initiated', 'total_size_bytes' => 1,
        ],
        UploadPart::class => [
            'upload_session_id' => $session->getKey(), 'part_no' => 1, 'size_bytes' => 1,
        ],
        NodeComment::class => [
            'node_id' => $node->getKey(), 'author_type' => 'user', 'author_id' => 1, 'body' => 'test',
        ],
        DocumentRevision::class => [
            'document_id' => Document::forceCreate(['node_id' => $node->getKey(), 'type' => 'tiptap', 'schema_version' => 1])->getKey(),
            'number' => 1, 'schema_version' => 1,
        ],
    };
    $before = $modelClass::query()->count();

    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($modelClass, $attributes): never {
        (new $modelClass)->forceFill($attributes)->save();

        throw new RuntimeException('failure after model insertion');
    }))->toThrow(RuntimeException::class, 'failure after model insertion');

    expect($modelClass::query()->count())->toBe($before);
})->with([
    UploadSession::class,
    UploadPart::class,
    NodeComment::class,
    DocumentRevision::class,
]);

it('takes the aggregate lock in shared mode for single-parent writes on PostgreSQL', function (): void {
    ForkRace::skipUnlessSupported($this);
    $database = app(VaulterDatabase::class);
    $modes = "SELECT mode FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() ORDER BY mode";

    $database->transaction(function () use ($database, $modes): void {
        $database->lockAggregateShared('outbox', '7');
        $database->lockAggregateShared('outbox', '7');

        expect(array_column($database->connection()->select($modes), 'mode'))->toBe(['ShareLock']);

        $database->lockAggregate('outbox', '8');

        expect(array_column($database->connection()->select($modes), 'mode'))->toBe(['ExclusiveLock', 'ShareLock']);
    });
});
