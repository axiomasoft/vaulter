<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\DriveProvisioner;
use Vaulter\Drive\Policies\UploadPolicy as EffectiveUploadPolicy;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\RevisionRetentionPolicy;
use Vaulter\Profiles\UploadPolicy;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(fn () => DriveTestHelpers::insideOperation());

uses(TestCase::class, RefreshDatabase::class);

function driveProfile(Profile $profile): void
{
    $registry = new ProfileRegistry;
    $registry->register($profile);
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    app()->forgetInstance(DriveProvisioner::class);
}

beforeEach(fn () => Storage::fake('local'));

it('applies disk and quota defaults only on a new drive', function (): void {
    driveProfile((new Profile)->key('default')->disk('local')->defaultQuota(100));
    $host = DriveTestHelpers::host();
    $drive = Vaulter::drive($host)->model();
    expect($drive->disk)->toBe('local')->and($drive->quota_bytes)->toBe(100);
    driveProfile((new Profile)->key('default')->disk('other')->defaultQuota(200));
    $same = Vaulter::drive($host)->model();
    expect($same->id)->toBe($drive->id)->and($same->disk)->toBe('local')->and($same->quota_bytes)->toBe(100);
    $fresh = Vaulter::drive(DriveTestHelpers::host())->model();
    expect($fresh->disk)->toBe('other')->and($fresh->quota_bytes)->toBe(200);
});

it('inherits nullable quota defaults and accepts zero', function (): void {
    config()->set('vaulter-drive.quota.default_bytes', 50);
    expect(Vaulter::drive(DriveTestHelpers::host())->model()->quota_bytes)->toBe(50);
    driveProfile((new Profile)->key('default')->defaultQuota(0));
    expect(Vaulter::drive(DriveTestHelpers::host())->model()->quota_bytes)->toBe(0);
});

it('checks owner restrictions before either provisioning or returning an existing drive', function (): void {
    $host = DriveTestHelpers::host();
    Vaulter::drive($host);
    driveProfile((new Profile)->key('default')->owners(TestUser::class));
    expect(fn () => Vaulter::drive($host))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => Vaulter::drive(DriveTestHelpers::host()))->toThrow(ProfileRestrictionException::class)
        ->and(Drive::query()->count())->toBe(1);
});

it('intersects MIME predicates and size limits while combining blocked extensions', function (): void {
    config()->set('vaulter-drive.uploads', ['max_file_size' => 100, 'allowed_mime_types' => ['image/*'], 'blocked_extensions' => ['EXE']]);
    $profile = (new Profile)->key('p')->uploads(new UploadPolicy(maxFileSize: 70, allowedMimeTypes: ['image/png'], blockedExtensions: ['.PHP']));
    $policy = app(EffectiveUploadPolicy::class);
    $policy->assertAllowed($profile, 69, 'image/png', 'png');
    expect($policy->maxFileSize($profile))->toBe(70)->and($policy->blockedExtensions($profile))->toBe(['exe', 'php'])
        ->and(fn () => $policy->assertAllowed($profile, 71, 'image/png', 'png'))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => $policy->assertAllowed($profile, 10, 'image/jpeg', 'jpg'))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => $policy->assertAllowed($profile, 10, 'image/png', 'EXE'))->toThrow(ProfileRestrictionException::class);
    config()->set('vaulter-drive.uploads.allowed_mime_types', ['text/*']);
    expect($policy->mimeAllowed($profile, 'image/png'))->toBeFalse();
});

it('uses the profile retention cutoff per drive and preserves an explicit maintenance override', function (): void {
    $short = new ProfileRegistry;
    $short->register((new Profile)->key('default')->trashRetentionDays(30));
    $short->register((new Profile)->key('short')->trashRetentionDays(0));
    $short->freeze();
    app()->instance(ProfileRegistry::class, $short);
    app()->forgetInstance(DriveProvisioner::class);
    app()->forgetInstance(ScanPolicyResolver::class);
    app()->forgetInstance(\Vaulter\Drive\Content\ScanPolicyResolver::class);
    $first = Vaulter::drive(DriveTestHelpers::host())->model();
    $second = Vaulter::drive(DriveTestHelpers::host(), 'short')->model();
    $a = (new Node)->forceFill(['drive_id' => $first->id, 'parent_id' => $first->root_node_id, 'kind' => 'folder', 'name' => 'keep', 'trashed_at' => Carbon::now()->subDays(2)]);
    $a->save();
    $a->forceFill(['trash_root_id' => $a->getKey()])->save();
    $b = (new Node)->forceFill(['drive_id' => $second->id, 'parent_id' => $second->root_node_id, 'kind' => 'folder', 'name' => 'purge', 'trashed_at' => Carbon::now()->subDays(2)]);
    $b->save();
    $b->forceFill(['trash_root_id' => $b->getKey()])->save();
    $this->artisan('vaulter:trash:purge')->assertSuccessful();
    expect(Node::query()->whereKey($a->id)->exists())->toBeTrue()->and(Node::query()->whereKey($b->id)->exists())->toBeFalse();
    $this->artisan('vaulter:trash:purge', ['--days' => 0])->assertSuccessful();
    expect(Node::query()->whereKey($a->id)->exists())->toBeFalse();
});

it('prunes by the profile revision limit while preserving head, pinned revisions and the allocation high-watermark', function (): void {
    // revisionLimit(2) keeps the head and one more unpinned revision; age protection off.
    driveProfile((new Profile)->key('default')
        ->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy->revisionRetention(new RevisionRetentionPolicy(minNoncurrentAgeDays: 0)))
        ->revisionLimit(2));
    $handle = DriveTestHelpers::drive();
    $node = $handle->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('one'), 'a.txt'));
    $handle->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'a.txt'));
    $handle->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('three'), 'a.txt'));
    $handle->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('four'), 'a.txt'));
    $file = DriveTestHelpers::model($node)->file()->firstOrFail()->refresh();
    $file->versions()->where('number', 1)->update(['keep_forever' => true]);
    $used = $handle->model()->refresh()->used_bytes;

    $this->artisan('vaulter:revisions:prune')->assertSuccessful();

    // Revision 2 ('two', 3 bytes) goes and its bytes leave the quota (PLAN2.D48).
    expect($file->versions()->orderBy('number')->pluck('number')->all())->toBe([1, 3, 4])
        ->and($file->refresh()->revision_count)->toBe(4)->and($handle->model()->refresh()->used_bytes)->toBe($used - 3);
    $handle->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('five'), 'a.txt'));
    expect(FileRevision::query()->max('number'))->toBe(5);
});
