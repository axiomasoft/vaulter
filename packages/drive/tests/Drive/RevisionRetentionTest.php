<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanPolicyResolver as DriveScanPolicyResolver;
use Vaulter\Drive\Contracts\Content\ScanPolicyResolver;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Jobs\PruneFileRevisionsJob;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\RevisionRetentionPolicy;
use Vaulter\Quota\QuotaCalculator;

/**
 * RevisionRetentionPolicy (PLAN2.D48, P4-I19): the pruning never deletes the head or a
 * pinned revision, respects the minimum non-current age, keeps the newest
 * maxNoncurrentRevisions unpinned non-current revisions, deletes those older than
 * maxNoncurrentAgeDays, and releases their bytes from the quota.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

function retentionProfile(RevisionRetentionPolicy $policy, string $key = 'default'): void
{
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->revisionRetention($key === 'default' ? $policy : new RevisionRetentionPolicy)));
    if ($key !== 'default') {
        $registry->register((new Profile)->key($key)->drivePolicy(fn (DrivePolicyBuilder $p) => $p->revisionRetention($policy)));
    }
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(DriveProvisioner::class);
    app()->forgetInstance(ScanPolicyResolver::class);
    app()->forgetInstance(DriveScanPolicyResolver::class);
}

/** @return array{0: Node, 1: list<int|string>} the node and its revision ids, oldest first */
function retentionFile(DriveHandle $drive, int $revisions, string $name = 'a.txt'): array
{
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('r1'), $name));
    for ($i = 2; $i <= $revisions; $i++) {
        $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('r'.$i), $name));
    }

    return [$node, DriveTestHelpers::model($node)->file()->firstOrFail()->versions()->orderBy('number')->pluck('id')->all()];
}

/** @return list<int> */
function retentionNumbers(Node|NodeSnapshot $node): array
{
    return DriveTestHelpers::model($node)->file()->firstOrFail()->versions()->orderBy('number')->pluck('number')->all();
}

it('keeps the head and the newest non-current revisions; a pinned one survives beyond the limit', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 2, minNoncurrentAgeDays: 0));
    $drive = DriveTestHelpers::drive();
    [$node, $ids] = retentionFile($drive, 4);
    [$pinnedNode, $pinnedIds] = retentionFile($drive, 4, 'pinned.txt');
    $drive->files()->setRevisionRetention($pinnedNode, $pinnedIds[0], keepForever: true);

    $this->artisan('vaulter:revisions:prune')->assertSuccessful();

    expect(retentionNumbers($node))->toBe([2, 3, 4])
        ->and(retentionNumbers($pinnedNode))->toBe([1, 2, 3, 4])
        ->and(DriveTestHelpers::model($node)->file()->firstOrFail()->current_revision_id)->toBe($ids[3])
        ->and($drive->model()->refresh()->used_bytes)->toBe(app(QuotaCalculator::class)->usedBytes($drive->model()->id))
        ->and($drive->model()->used_bytes)->toBe(3 * 2 + 4 * 2);

    // A second run finds nothing more.
    $this->artisan('vaulter:revisions:prune')->expectsOutputToContain('Deleted 0 revisions')->assertSuccessful();
});

it('never prunes a revision superseded less than minNoncurrentAgeDays ago', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 0, minNoncurrentAgeDays: 7));
    $drive = DriveTestHelpers::drive();
    [$node, $ids] = retentionFile($drive, 3);
    app(VaulterDatabase::class)->write(fn () => FileRevision::query()->whereKey($ids[0])->update(['superseded_at' => Carbon::now()->subDays(8)]));

    $this->artisan('vaulter:revisions:prune')->assertSuccessful();

    expect(retentionNumbers($node))->toBe([2, 3]);
});

it('prunes by age past maxNoncurrentAgeDays even under the count limit', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 10, maxNoncurrentAgeDays: 30, minNoncurrentAgeDays: 1));
    $drive = DriveTestHelpers::drive();
    [$node, $ids] = retentionFile($drive, 3);
    app(VaulterDatabase::class)->write(fn () => FileRevision::query()->whereKey($ids[0])->update(['superseded_at' => Carbon::now()->subDays(31)]));
    app(VaulterDatabase::class)->write(fn () => FileRevision::query()->whereKey($ids[1])->update(['superseded_at' => Carbon::now()->subDays(29)]));

    $this->artisan('vaulter:revisions:prune')->assertSuccessful();

    expect(retentionNumbers($node))->toBe([2, 3]);
});

it('reports without deleting on --dry-run and prunes only the selected drives', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 0, minNoncurrentAgeDays: 0));
    $first = DriveTestHelpers::drive();
    $second = DriveTestHelpers::drive();
    [$a] = retentionFile($first, 3);
    [$b] = retentionFile($second, 3);
    $used = $first->model()->refresh()->used_bytes;

    $this->artisan('vaulter:revisions:prune', ['--dry-run' => true])->expectsOutputToContain('Would delete 4 revisions (8 bytes)')->assertSuccessful();
    expect(retentionNumbers($a))->toBe([1, 2, 3])->and($first->model()->refresh()->used_bytes)->toBe($used);

    $this->artisan('vaulter:revisions:prune', ['--drive' => [(string) $second->model()->id]])->assertSuccessful();
    expect(retentionNumbers($a))->toBe([1, 2, 3])->and(retentionNumbers($b))->toBe([3]);
});

it('prunes trashed files too and releases their bytes', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 0, minNoncurrentAgeDays: 0));
    $drive = DriveTestHelpers::drive();
    [$node] = retentionFile($drive, 3);
    $drive->nodes()->trash($node);

    app()->call([new PruneFileRevisionsJob($drive->model()->id), 'handle']);

    expect(retentionNumbers($node))->toBe([3])->and($drive->model()->refresh()->used_bytes)->toBe(2);
});

it('leaves recent history alone under the default policy and on drives of another profile', function (): void {
    retentionProfile(new RevisionRetentionPolicy(maxNoncurrentRevisions: 0, minNoncurrentAgeDays: 0), 'strict');
    $default = DriveTestHelpers::drive();
    $strict = Vaulter::drive(DriveTestHelpers::host(), 'strict')->asSystem('test fixture');
    [$a] = retentionFile($default, 3);
    [$b] = retentionFile($strict, 3);

    $this->artisan('vaulter:revisions:prune')->assertSuccessful();

    expect(retentionNumbers($a))->toBe([1, 2, 3])->and(retentionNumbers($b))->toBe([3]);
});

it('stamps superseded_at when a revision stops being the head, never on the head', function (): void {
    $drive = DriveTestHelpers::drive();
    [$node, $ids] = retentionFile($drive, 2);
    $restored = $drive->files()->restoreRevision($node, $ids[0]);

    $revisions = FileRevision::query()->whereIn('id', [...$ids, $restored->id])->get()->keyBy('id');
    expect($revisions[$ids[0]]->superseded_at)->not->toBeNull()
        ->and($revisions[$ids[1]]->superseded_at)->not->toBeNull()
        ->and($revisions[$restored->id]->superseded_at)->toBeNull();
});

it('validates the retention policy', function (Closure $build): void {
    expect($build)->toThrow(InvalidProfileException::class);
})->with([
    'negative count' => [fn () => new RevisionRetentionPolicy(maxNoncurrentRevisions: -1)],
    'zero max age' => [fn () => new RevisionRetentionPolicy(maxNoncurrentAgeDays: 0)],
    'negative min age' => [fn () => new RevisionRetentionPolicy(minNoncurrentAgeDays: -1)],
    'max age below min age' => [fn () => new RevisionRetentionPolicy(maxNoncurrentAgeDays: 3, minNoncurrentAgeDays: 7)],
    'unprotected pins' => [fn () => new RevisionRetentionPolicy(protectPinned: false)],
]);

it('maps the profile revision limit onto the retention policy', function (): void {
    $policy = (new Profile)->key('p')->revisionLimit(5)->getDrivePolicy()->revisionRetention;
    $defaults = (new Profile)->key('q')->getDrivePolicy()->revisionRetention;

    expect($policy->maxNoncurrentRevisions)->toBe(4)->and($policy->minNoncurrentAgeDays)->toBe(7)
        ->and($defaults->maxNoncurrentRevisions)->toBe(100)->and($defaults->maxNoncurrentAgeDays)->toBeNull()
        ->and($defaults->minNoncurrentAgeDays)->toBe(7)->and($defaults->protectPinned)->toBeTrue()
        ->and((new Profile)->key('r')->revisionLimit(null)->getDrivePolicy()->revisionRetention->prunes())->toBeFalse();
});
