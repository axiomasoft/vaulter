<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Jobs\PruneFileRevisionsJob;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\RevisionRetentionPolicy;
use Vaulter\Quota\QuotaCalculator;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * P4-I19 / P4-I18 on PostgreSQL: the pruning chooses its revisions without locks, so a
 * pin or a new revision committed between the choice and the delete must be honoured
 * by the delete's own F/R locks; concurrent deletes of one revision release its bytes
 * once.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy
        ->revisionRetention(new RevisionRetentionPolicy(maxNoncurrentRevisions: 0, minNoncurrentAgeDays: 0))));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(DriveProvisioner::class);
});

it('honours a pin and a new head committed between the prune choice and its deletes', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('r1'), 'a.txt'));
    foreach (['r2', 'r3', 'r4'] as $bytes) {
        $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), 'a.txt'));
    }
    $ids = DriveTestHelpers::model($node)->file()->firstOrFail()->versions()->orderBy('number')->pluck('id')->all();
    $driveId = $drive->model()->id;

    $outcomes = ForkRace::run([
        // The pruning stalls right after choosing r1..r3, before any delete.
        static function () use ($driveId): array {
            app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query): void {
                if (str_contains($query->sql, 'keep_forever') && str_contains($query->sql, 'order by')) {
                    usleep(400_000);
                }
            });

            return app()->call([new PruneFileRevisionsJob($driveId), 'run']);
        },
        static function () use ($drive, $node, $ids): bool {
            usleep(100_000);

            return $drive->files()->setRevisionRetention($node, $ids[1], keepForever: true)->keepForever;
        },
        static function () use ($drive, $node): int {
            usleep(100_000);

            return $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('r5'), 'a.txt'))->number;
        },
    ]);
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }

    $file = DriveTestHelpers::model($node)->file()->firstOrFail();
    expect($outcomes[0]->value)->toMatchArray(['candidates' => 3, 'deleted' => 2, 'skipped' => 1])
        ->and($outcomes[2]->value)->toBe(5)
        ->and($file->versions()->orderBy('number')->pluck('number')->all())->toBe([2, 4, 5])
        ->and(FileRevision::query()->findOrFail($ids[1])->keep_forever)->toBeTrue()
        ->and($file->currentRevision->number)->toBe(5)
        ->and($file->revision_count)->toBe(5)
        ->and($drive->model()->refresh()->used_bytes)->toBe(app(QuotaCalculator::class)->usedBytes($driveId))
        ->and($drive->model()->used_bytes)->toBe(3 * 2);
});

it('releases the bytes of a revision once when two deletes race', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('first bytes'), 'a.txt'));
    $first = DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id;
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('second'), 'a.txt'));

    $outcomes = ForkRace::run(array_map(
        static fn (): Closure => static function () use ($drive, $node, $first): bool {
            $drive->files()->deleteRevision($node, $first);

            return true;
        },
        [0, 1],
    ));

    $failed = array_values(array_filter($outcomes, static fn ($outcome): bool => !$outcome->ok));
    expect(count(array_filter($outcomes, static fn ($outcome): bool => $outcome->ok)))->toBe(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->failedWith(NodeNotFoundException::class))->toBeTrue($failed[0]->message ?? '')
        ->and(FileRevision::query()->whereKey($first)->exists())->toBeFalse()
        ->and($drive->model()->refresh()->used_bytes)->toBe(strlen('second'))
        ->and(app(QuotaCalculator::class)->usedBytes($drive->model()->id))->toBe(strlen('second'));
});
