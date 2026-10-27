<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter as LaravelDisk;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\CompletionClock;
use Vaulter\Drive\Uploads\CompletionDeadline;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;

// D19: one finite monotonic completion budget per request, checked between staged chunks,
// never reset by a DB retry. Fake clocks only — no real delay, network or storage server.
beforeEach(fn () => Storage::set('local', Storage::fake('uploads-deadline-'.getmypid())));

/** A fake monotonic clock that advances $step seconds on every reading. */
function steppingClock(float $step, int &$reads = 0): Closure
{
    $now = 0.0;

    return function () use (&$now, &$reads, $step): float {
        $reads++;
        $value = $now;
        $now += $step;

        return $value;
    };
}

function assembledDeadlineUpload(int $bytes = 200_000): UploadSession
{
    DriveTestHelpers::insideOperation();
    $drive = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($drive, totalSizeBytes: $bytes, targetName: 'slow.txt', parentId: $drive->root_node_id);
    app(TusUploadHandler::class)->patch(session: $session, chunk: UploadTestHelpers::streamFor(str_repeat('a', $bytes)), offset: 0);

    return app(UploadSessionManager::class)->markAssembled($session->refresh());
}

final class DeadlineUnboundedWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

it('D19-config types the completion budget as 1..300 seconds with a 60 second default', function (): void {
    expect(app(DriveConfig::class)->completeTimeoutSeconds())->toBe(60);
    config()->set('vaulter-drive.uploads.resumable.complete_timeout_seconds', 0);
    expect(app(DriveConfig::class)->completeTimeoutSeconds())->toBe(1);
    config()->set('vaulter-drive.uploads.resumable.complete_timeout_seconds', 3600);
    expect(app(DriveConfig::class)->completeTimeoutSeconds())->toBe(300);
    config()->set('vaulter-drive.uploads.resumable.complete_timeout_seconds', 'nope');
    expect(app(DriveConfig::class)->completeTimeoutSeconds())->toBe(60);
});

it('D19-slow-stream refuses a staged stream that outlives the budget between chunks without publishing', function (): void {
    $session = assembledDeadlineUpload();
    $context = DriveContext::system($session->storage, 'upload test');
    // 0.25 s per clock reading: the budget of 1 s ends after the first 64 KiB chunk of 200 KB.
    $deadline = CompletionDeadline::start(seconds: 1, clock: steppingClock(0.25));

    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session, deadline: $deadline))
        ->toThrow(UploadConflictException::class, 'did not complete within 1s');
    expect(FileRevision::query()->count())->toBe(0)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and($session->result_node_id)->toBeNull()
        ->and(app(StagedUpload::class)->size($session))->toBe(200_000);

    // The kept staged bytes make the same completion repeatable within a fresh request budget.
    $node = app(CompleteUploadAction::class)->execute(context: $context, session: $session, deadline: CompletionDeadline::start(seconds: 60, clock: steppingClock(0.001)));
    expect($session->refresh()->status)->toBe(UploadStatus::Committed)
        ->and($session->result_node_id)->toBe($node->id)
        ->and(FileRevision::query()->count())->toBe(1);
});

it('D19-retry shares one monotonic budget across attempts instead of resetting it', function (): void {
    $session = assembledDeadlineUpload(bytes: 16);
    $context = DriveContext::system($session->storage, 'upload test');
    $reads = 0;
    $deadline = CompletionDeadline::start(seconds: 2, clock: steppingClock(1.0, $reads));
    $deadline->assertOpen($session->upload_id); // attempt 1 spent its share before a rolled-back retry

    // The retry closure receives the SAME deadline: it is exhausted, not renewed.
    expect(fn () => app(CompleteUploadAction::class)->execute(context: $context, session: $session, deadline: $deadline))
        ->toThrow(UploadConflictException::class, 'did not complete within 2s');
    expect($deadline->remaining())->toBe(0.0)
        ->and($reads)->toBeGreaterThanOrEqual(3)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and(FileRevision::query()->count())->toBe(0);
});

it('D19-request starts the budget once before the session critical section of UploadOperations::complete', function (): void {
    $session = assembledDeadlineUpload(bytes: 16);
    $reads = 0;
    app()->instance(CompletionClock::class, new CompletionClock(app(DriveConfig::class), steppingClock(100.0, $reads)));
    config()->set('vaulter-drive.uploads.resumable.complete_timeout_seconds', 60);

    $uploads = app(DriveHandleFactory::class)->forDrive($session->storage)->asSystem('upload test')->uploads();
    expect(fn () => $uploads->complete($session))->toThrow(UploadConflictException::class, 'did not complete within 60s');
    expect($reads)->toBe(2)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and(FileRevision::query()->count())->toBe(0)
        ->and(app(StagedUpload::class)->size($session))->toBe(16);
});

it('D19-transport bounds a socket stream with the remaining budget and reads it', function (): void {
    [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($right, 'payload');
    fclose($right);
    $stream = CompletionDeadline::start(seconds: 5, clock: steppingClock(0.01))->bound(stream: $left, uploadId: 'up-socket');

    expect($stream->getContents())->toBe('payload');
    fclose($left);
});

it('D19-transport refuses a stream without an enforceable timeout as an unsupported capability', function (): void {
    stream_wrapper_register('vaulterdeadline', DeadlineUnboundedWrapper::class);
    try {
        $stream = fopen('vaulterdeadline://staged', 'rb');
        expect(fn () => CompletionDeadline::start(seconds: 5)->bound(stream: $stream, uploadId: 'up-user'))
            ->toThrow(UploadConflictException::class, 'does not support a bounded transport timeout');
        fclose($stream);
    } finally {
        stream_wrapper_unregister('vaulterdeadline');
    }
});

it('D19-interrupted rejects a staged stream that stops making progress', function (): void {
    $source = fopen('php://temp', 'r+b');
    fwrite($source, 'abc');
    rewind($source);
    $stream = CompletionDeadline::start(seconds: 5, clock: steppingClock(0.01))->bound(stream: $source, uploadId: 'up-cut');
    fclose($source);

    expect(fn () => $stream->read(10))->toThrow(TypeError::class);
});

it('D19-preflight refuses an unboundable remote disk before any staged open, CAS write or assemble', function (): void {
    $session = assembledDeadlineUpload(bytes: 16);
    $local = Storage::disk('local');
    // A strict spy adapter: ANY storage call (exists/open/write) would fail the test with a Mockery error instead.
    $spy = Mockery::mock(FilesystemAdapter::class);
    Storage::set('local', new LaravelDisk(new Flysystem($spy), $spy));
    $reads = 0;
    app()->instance(CompletionClock::class, new CompletionClock(app(DriveConfig::class), steppingClock(0.001, $reads)));

    $uploads = app(DriveHandleFactory::class)->forDrive($session->storage)->asSystem('upload test')->uploads();
    expect(fn () => $uploads->complete($session))->toThrow(UploadConflictException::class, 'does not support a bounded transport timeout');
    $spy->shouldNotHaveReceived();
    Storage::set('local', $local);
    expect($reads)->toBe(2)
        ->and($session->refresh()->status)->toBe(UploadStatus::Assembled)
        ->and(FileRevision::query()->count())->toBe(0)
        ->and(app(StagedUpload::class)->size($session))->toBe(16);
});
