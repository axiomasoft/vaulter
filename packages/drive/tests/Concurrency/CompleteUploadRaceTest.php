<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Uploads\CompleteUploadAction;
use Vaulter\Drive\Content\UploadCleanupReconciler;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Operations\DriveContext;
use Vaulter\Testing\Concurrency\ForkRace;

beforeEach(fn () => DriveTestHelpers::insideOperation());

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::set('local', Storage::fake('uploads-p37-'.getmypid()));
});

function completeRaceSession(): UploadSession
{
    $drive = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($drive, totalSizeBytes: 4, targetName: 'once.txt', parentId: $drive->root_node_id);
    app(TusUploadHandler::class)->patch(session: $session, chunk: UploadTestHelpers::streamFor('once'), offset: 0);

    return app(UploadSessionManager::class)->markAssembled($session->refresh());
}

it('V19 two complete calls commit one node revision edge quota update and event', function (): void {
    $session = completeRaceSession();
    $task = static function () use ($session): array {
        $fresh = $session->fresh();
        try {
            $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($fresh->storage, 'complete race'), session: $fresh);

            return ['node' => (string) $node->id, 'conflict' => false, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        } catch (UploadConflictException) {
            return ['node' => null, 'conflict' => true, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        }
    };
    $outcomes = ForkRace::run([$task, $task]);
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    expect($outcomes[0]->value['pid'])->not->toBe($outcomes[1]->value['pid']);
    $session->refresh();
    expect($session->status)->toBe(UploadStatus::Committed)->and(FileRevision::query()->count())->toBe(1)
        ->and(Blob::query()->count())->toBe(1)->and($session->storage()->firstOrFail()->used_bytes)->toBe(4)
        ->and(DB::table('v_file_revision_blobs')->count())->toBe(1)
        ->and(DB::table('v_activity')->where('verb', 'file.uploaded')->count())->toBe(1);
    $replay = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'complete replay'), session: $session);
    expect((string) $replay->id)->toBe((string) $session->result_node_id)->and(FileRevision::query()->count())->toBe(1);
});

it('SEC-CLEANUP-commit-kill surviving independent worker discovers durable cleanup after commit', function (): void {
    $session = completeRaceSession();
    $barrier = tempnam(sys_get_temp_dir(), 'vaulter-cleanup-crash-');
    unlink($barrier);
    try {
        $outcomes = ForkRace::run([
            static function () use ($session, $barrier): void {
                $database = app(VaulterDatabase::class);
                $armed = false;
                $database->connection()->listen(function (QueryExecuted $query) use ($database, $session, $barrier, &$armed): void {
                    if ($armed || $query->connection !== $database->connection()
                        || !str_starts_with($query->sql, 'update "'.$session->getTable().'"')
                        || !str_contains($query->sql, '"cleanup_pending"')
                        || !in_array(UploadStatus::Committed->value, $query->bindings, true)) {
                        return;
                    }
                    $armed = true;
                    // Nested transaction records execute their callbacks before
                    // the outer record. Register on Complete's own record before
                    // its cleanup callback, so the fault precedes staging deletion.
                    $database->afterCommit(static function () use ($barrier): void {
                        file_put_contents($barrier, 'committed');
                        posix_kill(getmypid(), SIGKILL);
                    });
                });
                $database->transaction(function () use ($session): void {
                    app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'crash upload'), session: $session);
                }, attempts: 1);
            },
            static function () use ($session, $barrier): array {
                $deadline = microtime(true) + 5;
                while (!is_file($barrier) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (!is_file($barrier)) {
                    throw new RuntimeException('Commit barrier was not reached.');
                }
                $fresh = $session->fresh();
                if ($fresh->status !== UploadStatus::Committed || !$fresh->cleanup_pending || app(StagedUpload::class)->size($fresh) !== 4) {
                    throw new RuntimeException(sprintf('Crash did not preserve committed cleanup state: status=%s pending=%s staging=%d.', $fresh->status->value, $fresh->cleanup_pending ? 'true' : 'false', app(StagedUpload::class)->size($fresh)));
                }

                return app(UploadCleanupReconciler::class)->reconcile(limit: 1, driveIds: [$fresh->drive_id]);
            },
        ]);
        expect($outcomes[0]->ok)->toBeFalse()->and($outcomes[0]->exception)->toBe('ForkRace\\MissingResult')
            ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
            ->and($outcomes[1]->value['cleanup_completed'])->toBe(1)
            ->and($session->refresh()->cleanup_pending)->toBeFalse()->and(FileRevision::query()->count())->toBe(1);
    } finally {
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
});

it('SEC-CLEANUP-mutex holds the session lock through the outer commit against abort and sweep', function (): void {
    $session = completeRaceSession();
    $barrier = tempnam(sys_get_temp_dir(), 'vaulter-cleanup-lock-');
    unlink($barrier);
    try {
        $outcomes = ForkRace::run([
            static function () use ($session, $barrier): string {
                return app(VaulterDatabase::class)->transaction(function () use ($session, $barrier): string {
                    $node = app(CompleteUploadAction::class)->execute(context: DriveContext::system($session->storage, 'locked upload'), session: $session);
                    if (app(StagedUpload::class)->size($session->fresh()) !== 4) {
                        throw new RuntimeException('Protocol staging disappeared before outer commit.');
                    }
                    file_put_contents($barrier, 'locked');
                    $deadline = microtime(true) + 5;
                    while (!is_file($barrier.'.checked') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (!is_file($barrier.'.checked')) {
                        throw new RuntimeException('Mutex contender never completed.');
                    }

                    return (string) $node->id;
                }, attempts: 1);
            },
            static function () use ($session, $barrier): int {
                $deadline = microtime(true) + 5;
                while (!is_file($barrier) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (!is_file($barrier)) {
                    throw new RuntimeException('Outer transaction never reached barrier.');
                }
                $conflicts = 0;
                try {
                    app(UploadSessionManager::class)->abort($session->fresh());
                } catch (UploadConflictException) {
                    $conflicts++;
                }
                try {
                    app(UploadSessionManager::class)->expire($session->fresh());
                } catch (UploadConflictException) {
                    $conflicts++;
                }
                file_put_contents($barrier.'.checked', 'done');

                return $conflicts;
            },
        ]);
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->message ?? '');
        }
        expect($outcomes[1]->value)->toBe(2)->and($session->refresh()->status)->toBe(UploadStatus::Committed)
            ->and(app(StagedUpload::class)->size($session))->toBe(0)->and(FileRevision::query()->count())->toBe(1);
    } finally {
        foreach ([$barrier, $barrier.'.checked'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});
