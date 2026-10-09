<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Drive\DriveHandleFactory;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\UploadOperations;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(fn () => DriveTestHelpers::insideOperation());

// Runs on COMMITTED rows (DatabaseMigrations, see tests/Pest.php): the retry is only
// honest at transaction level 0, which RefreshDatabase's wrapping transaction hides.

beforeEach(fn () => Storage::set('local', Storage::fake('uploads-retry-'.getmypid())));

function retryAssembledUpload(): UploadSession
{
    $drive = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($drive, totalSizeBytes: 4, targetName: 'once.txt', parentId: $drive->root_node_id);
    app(TusUploadHandler::class)->patch(session: $session, chunk: UploadTestHelpers::streamFor('once'), offset: 0);

    return app(UploadSessionManager::class)->markAssembled($session->refresh());
}

function retryUploadOperations(UploadSession $session): UploadOperations
{
    return app(DriveHandleFactory::class)->forDrive($session->storage)->asSystem('complete retry')->extension(UploadOperations::class);
}

/** The first $failures CAS commits fail like a deadlock victim; every other call is real. */
function flakyBlobStore(int $failures, int &$calls): void
{
    $real = app(BlobStore::class);
    $store = Mockery::mock(BlobStore::class);
    $store->shouldReceive('commitStaged')->andReturnUsing(function (...$arguments) use ($real, $failures, &$calls) {
        if (++$calls <= $failures) {
            throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5);
        }

        return $real->commitStaged(...$arguments);
    });
    foreach (['attach', 'detach', 'commit', 'read'] as $method) {
        $store->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $real->{$method}(...$arguments));
    }
    app()->instance(BlobStore::class, $store);
}

it('SEC-R19-retry completes after one concurrency failure by retrying the outermost unit', function (): void {
    $session = retryAssembledUpload();
    $calls = 0;
    flakyBlobStore(failures: 1, calls: $calls);

    $node = retryUploadOperations($session)->complete($session);

    expect($calls)->toBe(2)->and($node->exists)->toBeTrue()
        ->and($session->refresh()->status)->toBe(UploadStatus::Committed)
        ->and(FileRevision::query()->count())->toBe(1)
        ->and(app(StagedUpload::class)->size($session))->toBe(0);
});

it('SEC-R19-retry-exhausted maps the persistent failure to a retryable problem and keeps the staged bytes', function (): void {
    $session = retryAssembledUpload();
    $calls = 0;
    flakyBlobStore(failures: 99, calls: $calls);

    $caught = null;
    try {
        retryUploadOperations($session)->complete($session);
    } catch (ConcurrentUpdateException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(ProblemException::class)
        ->and($caught->problemCode())->toBe('concurrent_update')->and($caught->httpStatus())->toBe(409)
        ->and($calls)->toBe(3)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and(FileRevision::query()->count())->toBe(0)
        ->and(app(StagedUpload::class)->size($session))->toBe(4);
});

it('does not retry a non-concurrency failure', function (): void {
    $session = retryAssembledUpload();
    $calls = 0;
    $real = app(BlobStore::class);
    $store = Mockery::mock(BlobStore::class);
    $store->shouldReceive('commitStaged')->andReturnUsing(function () use (&$calls): never {
        $calls++;
        throw new RuntimeException('disk full');
    });
    foreach (['attach', 'detach', 'commit', 'read'] as $method) {
        $store->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $real->{$method}(...$arguments));
    }
    app()->instance(BlobStore::class, $store);

    expect(fn () => retryUploadOperations($session)->complete($session))->toThrow(RuntimeException::class, 'disk full')
        ->and($calls)->toBe(1);
});
