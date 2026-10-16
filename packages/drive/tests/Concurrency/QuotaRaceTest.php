<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;

it('V02 admits exactly one 8 MiB upload into 10 MiB of free quota', function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $drive->model()->forceFill(['quota_bytes' => 10 * 1024 * 1024])->save();
    // Different parents prevent the destination lock from hiding a broken quota UPDATE.
    $parents = [$drive->folders()->create('A'), $drive->folders()->create('B')];
    $outcomes = ForkRace::run(array_map(
        static fn (int $i): Closure => static fn (): string => (string) $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor(str_repeat((string) $i, 8 * 1024 * 1024)), $i.'.bin'), new UploadOptions(parent: $parents[$i]))->id,
        [0, 1],
    ));
    $won = array_values(array_filter($outcomes, static fn (RaceOutcome $o): bool => $o->ok));
    $lost = array_values(array_filter($outcomes, static fn (RaceOutcome $o): bool => !$o->ok));
    expect($won)->toHaveCount(1)->and($lost)->toHaveCount(1);
    expect($lost[0]->failedWith(QuotaExceededException::class))->toBeTrue($lost[0]->message ?? '');
    expect($drive->model()->refresh()->used_bytes)->toBe(8 * 1024 * 1024)
        ->and((int) File::query()->sum('size_bytes'))->toBe(8 * 1024 * 1024)
        ->and(Blob::query()->count())->toBe(1)
        ->and(FileRevisionBlob::query()->count())->toBe(1);
    $blob = Blob::query()->sole();
    // Age the actual disk object, not Carbon: orphan grace uses filesystem mtime.
    touch(Storage::disk('local')->path($blob->storage_key), time() - 48 * 3600);
    $this->artisan('vaulter:blobs:reconcile', ['--orphans' => true, '--prune' => true, '--orphan-grace' => 24])->assertSuccessful();
    Storage::disk('local')->assertExists($blob->storage_key);
    expect(hash_file('sha256', Storage::disk('local')->path($blob->storage_key)))->toBe($blob->sha256);
});
