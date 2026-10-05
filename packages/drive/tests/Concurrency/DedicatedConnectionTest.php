<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Cas\BlobLock;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\CreateFolderAction;
use Vaulter\Drive\Data\Nodes\CreateFolderInput;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Concurrency\ForkRace;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('database.connections.vaulter', config('database.connections.testing'));
    config()->set('vaulter.connection', 'vaulter');
});

afterEach(function (): void {
    config()->set('vaulter.connection', null);
});

it('rolls back an Action insertion on the dedicated Vaulter connection', function (): void {
    $storage = UploadTestHelpers::storage();
    $before = Node::query()->count();

    Event::listen('eloquent.created: '.Node::class, function (Node $node): never {
        expect($node->getConnection())->toBe(app(VaulterDatabase::class)->connection())
            ->and($node->getConnection()->transactionLevel())->toBe(1);
        throw new RuntimeException('failure after insertion');
    });

    expect(fn () => app(CreateFolderAction::class)->execute(new CreateFolderInput(
        storage: $storage,
        name: 'must roll back',
        parent: $storage->rootNode,
    )))->toThrow(RuntimeException::class, 'failure after insertion');

    expect(Node::query()->count())->toBe($before);
});

it('takes the CAS advisory lock on the dedicated connection only', function (): void {
    $database = app(VaulterDatabase::class);
    $sql = "SELECT count(*) AS locks FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()";

    $database->transaction(function () use ($database, $sql): void {
        app(BlobLock::class)->acquire('s1', str_repeat('a', 64));

        expect((int) $database->connection()->selectOne($sql)->locks)->toBe(1)
            ->and((int) DB::connection()->selectOne($sql)->locks)->toBe(0);
    });

    expect((int) $database->connection()->selectOne($sql)->locks)->toBe(0);
});
