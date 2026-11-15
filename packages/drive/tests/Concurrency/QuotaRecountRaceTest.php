<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Actions\Drives\RecountDriveQuotaAction;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Quota\QuotaCalculator;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * A recount racing an upload or a revision delete never overwrites the
 * quota with a sum read before the concurrent change committed. After both finish,
 * used_bytes equals the sum of the retained revisions exactly.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('a recount racing an upload leaves used_bytes equal to the revision sum', function (): void {
    $drive = DriveTestHelpers::drive();
    $driveId = $drive->model()->getKey();
    $parents = [$drive->folders()->create('A'), $drive->folders()->create('B')];
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('seed-bytes'), 'seed.bin'), new UploadOptions(parent: $parents[0]));
    Drive::query()->whereKey($driveId)->update(['used_bytes' => 999]); // drift to be corrected

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(RecountDriveQuotaAction::class)->execute($driveId)['after'],
        static fn (): string => (string) $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor(str_repeat('u', 64)), 'late.bin'), new UploadOptions(parent: $parents[1]))->id,
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '');
    // Both orders end in the exact sum: the upload either sat inside the counted sum or added on top.
    $sum = app(QuotaCalculator::class)->usedBytes($driveId);
    expect($sum)->toBe(10 + 64)
        ->and(Drive::query()->findOrFail($driveId)->used_bytes)->toBe($sum);
});

it('a recount racing a revision delete leaves used_bytes equal to the revision sum', function (): void {
    $drive = DriveTestHelpers::drive();
    $driveId = $drive->model()->getKey();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first'), 'a.bin'));
    $second = $drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('second-bytes'), 'a.bin'));
    $revisions = $drive->files()->revisions($file)->items;
    $old = array_values(array_filter($revisions, static fn ($revision): bool => $revision->id !== $second->id))[0];
    Drive::query()->whereKey($driveId)->update(['used_bytes' => 1]);

    $outcomes = ForkRace::run([
        static fn (): string => (string) app(RecountDriveQuotaAction::class)->execute($driveId)['after'],
        static function () use ($drive, $file, $old): string {
            $drive->files()->deleteRevision($file, $old);

            return 'deleted';
        },
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '');
    $sum = app(QuotaCalculator::class)->usedBytes($driveId);
    expect($sum)->toBe(12)
        ->and(Drive::query()->findOrFail($driveId)->used_bytes)->toBe($sum);
});
