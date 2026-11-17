<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Repositories\FileRevisionStoreRepository;
use Vaulter\Drive\Repositories\FileStoreRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
});

it('V03 serializes revisions and accounts for every retained revision', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('old'), 'a.txt'));
    // Both children inherit revision 1, even after the winner commits revision 2.
    $outcomes = ForkRace::run(array_map(
        static fn (string $bytes): Closure => static fn (): string => $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), 'a.txt'))->id,
        ['short', 'a much longer head'],
    ));
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }

    $file = DriveTestHelpers::model($node)->file()->firstOrFail();
    $head = $file->currentRevision;
    expect($file->versions()->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and($file->revision_count)->toBe(3)
        ->and($head->number)->toBe(3)
        ->and(DriveTestHelpers::model($node)->file()->value('current_revision_id'))->toBe($head->id)
        // Every retained revision counts.
        ->and($drive->model()->refresh()->used_bytes)->toBe(strlen('old') + strlen('short') + strlen('a much longer head'))
        ->and(DriveTestHelpers::model($node)->size_bytes)->toBe($head->size_bytes);
});

// Upload's node lock ALSO serializes revisions. Exercise the aggregate repository
// independently so removing its FOR UPDATE is a meaningful negative control.
it('V03 aggregate lock protects counter allocation even without a caller node lock', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('head'), 'a.txt'));
    $file = DriveTestHelpers::model($node)->file()->firstOrFail();
    $outcomes = ForkRace::run(array_map(
        static fn (int $i): Closure => static fn (): int => app(VaulterDatabase::class)->transaction(function () use ($file): int {
            $files = app(FileStoreRepository::class);
            $locked = $files->lockForUpdate($file);
            $head = $locked->currentRevision;
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
    $stream = Utils::streamFor($reader);
    expect($stream->isSeekable())->toBeFalse();
    try {
        $drive->files()->upload(UploadSource::fromPsrStream($stream, 'a.txt'));
    } finally {
        $stream->close();
    }

    expect($injected)->toBeTrue()
        ->and($drive->model()->refresh()->used_bytes)->toBe(20)
        ->and(File::query()->count())->toBe(1)
        ->and(FileRevision::query()->sole()->number)->toBe(1)
        ->and(Blob::query()->count())->toBe(1)
        ->and(Blob::query()->sole()->sha256)->toBe(hash('sha256', 'retry the same bytes'))
        ->and(glob(sys_get_temp_dir().'/vaulter_cas_*') ?: [])->toBe($before);
})->group('serial', 'nofast');
