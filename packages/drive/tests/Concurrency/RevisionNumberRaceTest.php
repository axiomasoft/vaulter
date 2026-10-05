<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
});

it('V03 serializes revisions and accounts for their actual preceding heads', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('old'), originalName: 'a.txt');
    // Both children inherit revision 1, even after the winner commits revision 2.
    $outcomes = ForkRace::run(array_map(
        static fn (string $bytes): Closure => static fn (): string => (string) $drive->uploadVersion(
            $node, DriveTestHelpers::streamFor($bytes), originalName: 'a.txt',
        )->current_version_id,
        ['short', 'a much longer head'],
    ));
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }

    $file = $node->file()->firstOrFail();
    $head = $file->currentVersion;
    expect($file->versions()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and($file->revision_count)->toBe(3)
        ->and($head->number)->toBe(3)
        ->and($node->refresh()->current_version_id)->toBe($head->id)
        ->and($drive->model()->refresh()->used_bytes)->toBe($head->size_bytes)
        ->and($node->size_bytes)->toBe($head->size_bytes);
});

// Upload's node lock ALSO serializes revisions. Exercise the aggregate repository
// independently so removing its FOR UPDATE is a meaningful negative control.
it('V03 aggregate lock protects counter allocation even without a caller node lock', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('head'), originalName: 'a.txt');
    $file = $node->file;
    $outcomes = ForkRace::run(array_map(
        static fn (int $i): Closure => static fn (): int => app(VaulterDatabase::class)->transaction(function () use ($file): int {
            $files = app(FileStoreRepository::class);
            $locked = $files->lockForUpdate($file);
            $head = $locked->currentVersion;
            // Widen the read/write overlap: an unlocked reader also sees counter 1.
            usleep(100_000);
            $versionNo = $locked->revision_count + 1;
            $attributes = $head->getAttributes();
            unset($attributes[$head->getKeyName()]);
            $attributes['number'] = $versionNo;
            $version = app(FileRevisionStoreRepository::class)->create($attributes);
            $files->repointHead($locked, $version->id, $head->mime_type, $head->size_bytes, $versionNo);

            return $versionNo;
        }),
        [0, 1],
    ));
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }

    $numbers = array_map(static fn (RaceOutcome $o): int => $o->value, $outcomes);
    sort($numbers);
    expect($numbers)->toBe([2, 3])
        ->and(File::query()->findOrFail($file->id)->revision_count)->toBe(3)
        ->and(FileRevision::query()->where('file_id', $file->id)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3]);
});

it('reuses prepared bytes on a serialization retry and discards the temp file', function (): void {
    $drive = DriveTestHelpers::drive();
    $before = glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [];
    $injected = false;
    app(VaulterDatabase::class)->connection()->listen(function ($query) use (&$injected): void {
        if (!$injected && str_starts_with((string) $query->sql, 'insert into "v_files"')) {
            $injected = true;
            throw new PDOException('serialization failure', 40001);
        }
    });
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();
    [$reader, $writer] = $pair;
    fwrite($writer, 'retry the same bytes');
    fclose($writer);
    try {
        $drive->upload($reader, originalName: 'a.txt');
    } finally {
        fclose($reader);
    }

    expect($injected)->toBeTrue()
        ->and($drive->model()->refresh()->used_bytes)->toBe(20)
        ->and(File::query()->count())->toBe(1)
        ->and(FileRevision::query()->sole()->number)->toBe(1)
        ->and(Blob::query()->count())->toBe(1)
        ->and(Blob::query()->sole()->sha256)->toBe(hash('sha256', 'retry the same bytes'))
        ->and(glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [])->toBe($before);
});
