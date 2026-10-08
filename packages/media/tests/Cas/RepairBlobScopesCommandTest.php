<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\FailingPromoteContentStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Scope repair acceptance requires PostgreSQL.');
    }
    Storage::fake('local');
});

it('requires exactly one repair mode', function (): void {
    $this->artisan('vaulter:blobs:repair-scopes')->assertExitCode(2);
    $this->artisan('vaulter:blobs:repair-scopes', ['--dry-run' => true, '--execute' => true])->assertExitCode(2);
});

it('splits mixed storage edges without losing bytes and resumes a partial failure', function (bool $fail): void {
    $store = app(BlobStore::class);
    $old = $store->commit(CasTestHelpers::streamFor('legacy shared'), BlobScope::legacy('snull'));
    $a = CasTestHelpers::makeVersion();
    $b = CasTestHelpers::makeVersion();
    foreach ([$a, $b] as $version) {
        $version->forceFill(['blob_id' => $old->getKey()])->save();
        $store->attach($version, $old);
    }
    $old->forceFill(['ref_count' => 2])->save();
    expect(Artisan::call('vaulter:blobs:repair-scopes', ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();
    expect(preg_match_all('/\|\s*move\s*\|/', $output))->toBe(2);
    foreach ([$a, $b] as $version) {
        expect($output)->toContain('drive:'.$version->file->node->storage->getKey());
    }
    expect(Blob::query()->count())->toBe(1)->and(FileRevisionBlob::query()->where('blob_id', $old->getKey())->count())->toBe(2);
    $content = app(ContentStore::class);
    if ($fail) {
        app()->instance(ContentStore::class, new FailingPromoteContentStore($content, 2));
        $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->assertExitCode(1);
        expect(FileRevisionBlob::query()->where('blob_id', $old->getKey())->count())->toBe(1)
            ->and(Blob::query()->count())->toBe(2);
        app()->instance(ContentStore::class, $content);
    }
    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->assertSuccessful();
    foreach ([$a, $b] as $version) {
        $blob = Blob::query()->where('scope_key', 'drive:'.$version->file->node->storage->getKey())->sole();
        expect($version->fresh()->blob_id)->toBe($blob->getKey())
            ->and(FileRevisionBlob::query()->where('file_revision_id', $version->getKey())->sole()->blob_id)->toBe($blob->getKey())
            ->and($blob->ref_count)->toBe(1)->and(stream_get_contents($store->read($blob)))->toBe('legacy shared');
    }
    expect($old->fresh()->ref_count)->toBe(0)->and(Blob::query()->count())->toBe(3);
    Storage::disk('local')->assertExists($old->storage_key);
    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->expectsOutputToContain('Repaired: 0')->assertSuccessful();
    expect(Blob::query()->count())->toBe(3);
    $entries = array_map(static fn ($line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file(storage_path('logs/vaulter-repair-scopes.log'), FILE_IGNORE_NEW_LINES));
    expect(array_filter($entries, static fn ($entry): bool => $entry['action'] === 'move' && $entry['edges'] === 1))->not->toBe([]);
})->with([false, true]);

it('moves to the drive for a host without tenants and reports unreferenced and corrupt bytes', function (): void {
    $store = app(BlobStore::class);
    $old = $store->commit(CasTestHelpers::streamFor('host legacy'), BlobScope::legacy('tnull'));
    $version = CasTestHelpers::makeVersion();
    $store->attach($version, $old);
    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->assertSuccessful();
    expect(Blob::query()->where('scope_key', 'drive:'.$version->file->node->storage->getKey())->count())->toBe(1)
        ->and(Blob::query()->where('scope_key', 'global')->count())->toBe(0);
    $this->artisan('vaulter:blobs:repair-scopes', ['--dry-run' => true])->expectsOutputToContain('unreferenced')->assertSuccessful();
    $bad = $store->commit(CasTestHelpers::streamFor('bad legacy'), BlobScope::legacy('snull'));
    $other = CasTestHelpers::makeVersion();
    $store->attach($other, $bad);
    Storage::disk('local')->put($bad->storage_key, 'tampered');
    $this->artisan('vaulter:blobs:repair-scopes', ['--dry-run' => true])->expectsOutputToContain('hash_mismatch')->assertSuccessful();
    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->expectsOutputToContain('hash_mismatch')->assertSuccessful();
    expect(FileRevisionBlob::query()->where('blob_id', $bad->getKey())->count())->toBe(1);
});

it('moves retired global blobs into the tenant or the drive of every referencing revision', function (string $segment): void {
    $store = app(BlobStore::class);
    $old = $store->commit(CasTestHelpers::streamFor('install-wide'), BlobScope::fromSegment($segment));
    expect($old->scope_key)->toBe('global');
    $tenanted = CasTestHelpers::makeVersion();
    $tenanted->file->node->storage->forceFill(['tenant_id' => '77'])->save();
    $plain = CasTestHelpers::makeVersion();
    foreach ([$tenanted, $plain] as $version) {
        $version->forceFill(['blob_id' => $old->getKey()])->save();
        $store->attach($version, $old);
    }

    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->assertSuccessful();

    $tenantBlob = Blob::query()->where('scope_key', 'tenant:77')->sole();
    $driveBlob = Blob::query()->where('scope_key', 'drive:'.$plain->file->node->storage->getKey())->sole();
    expect(FileRevisionBlob::query()->where('file_revision_id', $tenanted->getKey())->sole()->blob_id)->toBe($tenantBlob->getKey())
        ->and(FileRevisionBlob::query()->where('file_revision_id', $plain->getKey())->sole()->blob_id)->toBe($driveBlob->getKey())
        ->and(FileRevisionBlob::query()->where('blob_id', $old->getKey())->exists())->toBeFalse()
        ->and(stream_get_contents($store->read($tenantBlob)))->toBe('install-wide')
        ->and(stream_get_contents($store->read($driveBlob)))->toBe('install-wide');
    $this->artisan('vaulter:blobs:repair-scopes', ['--execute' => true])->expectsOutputToContain('Repaired: 0')->assertSuccessful();
})->with(['g', 'global']);
