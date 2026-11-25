<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;

it('completes ten overlapping move/upload races with an acyclic tree and exact head bytes', function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('F');
    for ($i = 0; $i < 10; $i++) {
        $moving = $drive->folders()->create('A'.$i);
        $outcomes = ForkRace::run([
            static function () use ($drive, $moving, $folder): array {
                $attempts = 0;
                app('events')->listen(TransactionBeginning::class, static function (TransactionBeginning $event) use (&$attempts): void {
                    if ($event->connection->transactionLevel() === 1) {
                        $attempts++;
                    }
                });
                $node = app(MoveNodeAction::class)->execute(DriveContext::system($drive->model(), 'test fixture'), new MoveNodeInput(node: DriveTestHelpers::model($moving), into: DriveTestHelpers::model($folder)));

                return ['id' => $node->id, 'attempts' => $attempts];
            },
            static function () use ($drive, $i, $folder): array {
                $attempts = 0;
                app('events')->listen(TransactionBeginning::class, static function (TransactionBeginning $event) use (&$attempts): void {
                    if ($event->connection->transactionLevel() === 1) {
                        $attempts++;
                    }
                });
                $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('upload '.$i), $i.'.txt'), new UploadOptions(parent: $folder));

                return ['id' => $node->id, 'attempts' => $attempts];
            },
        ]);
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->message ?? '');
            expect($outcome->value['attempts'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);
        }
        foreach (Node::query()->get() as $node) {
            $seen = [];
            while ($node->parent_id !== null) {
                expect(in_array($node->id, $seen, true))->toBeFalse('cycle at node '.$node->id);
                $seen[] = $node->id;
                $node = Node::query()->findOrFail($node->parent_id);
            }
            expect((string) $node->id)->toBe($drive->root()->id);
        }
        expect($drive->model()->refresh()->used_bytes)->toBe((int) File::query()->sum('size_bytes'));
    }
});

it('copy and dedup upload finish with overlapping quota operations and no outer retry', function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $source = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('shared bytes'), 'source.txt'));
    $copyParent = $drive->folders()->create('copies');
    $uploadParent = $drive->folders()->create('uploads');
    $barrier = sys_get_temp_dir().'/vlt_copy_quota_'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $wait = static function (Closure $condition): void {
        $deadline = microtime(true) + 5;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Copy/quota overlap barrier timed out.');
            }
            usleep(1_000);
        }
    };
    try {
        $outcomes = ForkRace::run([
            static function () use ($drive, $uploadParent, $barrier, $wait): string {
                $db = app(VaulterDatabase::class)->connection();
                $db->listen(static function ($query) use ($db, $barrier, $wait): void {
                    if (!str_starts_with($query->sql, 'update "v_drives" set used_bytes = used_bytes +')) {
                        return;
                    }
                    file_put_contents($barrier.'/quota-held', '1');
                    $wait(static fn (): bool => is_file($barrier.'/copy-pid'));
                    $pid = (int) file_get_contents($barrier.'/copy-pid');
                    $wait(static function () use ($db, $pid): bool {
                        $db->select('SELECT pg_stat_clear_snapshot()');

                        // The copy queues behind this upload while it holds the quota: on the shared blob's
                        // advisory lock or on the drive row, whichever the copy reaches first.
                        return $db->table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')->exists();
                    });
                });

                // No outer retry: expose a lock-order cycle rather than hide it.
                return $db->transaction(static fn (): string => $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('shared bytes'), 'dedup.txt'), new UploadOptions(parent: $uploadParent))->id, 1);
            },
            static function () use ($drive, $source, $copyParent, $barrier, $wait): string {
                $db = app(VaulterDatabase::class)->connection();
                file_put_contents($barrier.'/copy-pid', (string) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid);
                $wait(static fn (): bool => is_file($barrier.'/quota-held'));

                return $db->transaction(static fn (): string => $drive->nodes()->copy($source, destination: $copyParent, options: new CopyOptions(name: 'copy.txt'))->id, 1);
            },
        ], timeoutMs: 12_000);
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->exception.': '.$outcome->message);
        }
        expect($drive->model()->refresh()->used_bytes)->toBe(3 * strlen('shared bytes'))
            ->and((int) File::query()->sum('size_bytes'))->toBe(3 * strlen('shared bytes'));
    } finally {
        array_map(unlink(...), glob($barrier.'/*') ?: []);
        rmdir($barrier);
    }
});
