<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Quota\QuotaCalculator;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * vaulter:drives:recount: used_bytes is replaced by the sum of every
 * retained revision, trash included, drive by drive; --dry-run only reports.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

/** @return array{0: DriveHandle, 1: int|string} */
function recountFixture(int $drift): array
{
    $drive = DriveTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('abcde'), 'a.bin'));
    $drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdefgh'), 'a.bin'));
    $trashed = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('xyz'), 'b.bin'));
    $drive->nodes()->trash($trashed);
    $id = $drive->model()->getKey();
    // 5 + 8 (both revisions of a.bin) + 3 (trashed b.bin) = 16
    expect(app(QuotaCalculator::class)->usedBytes($id))->toBe(16);
    Drive::query()->whereKey($id)->update(['used_bytes' => 16 + $drift]);

    return [$drive, $id];
}

it('recounts all revisions including the trash and reports the drift', function (): void {
    Log::spy();
    [, $id] = recountFixture(drift: 100);

    $this->artisan('vaulter:drives:recount', ['--drive' => [(string) $id]])
        ->expectsOutputToContain('stored 116, counted 16 (-100), corrected')
        ->assertSuccessful();

    expect(Drive::query()->findOrFail($id)->used_bytes)->toBe(16);
    Log::shouldHaveReceived('warning')->once();
});

it('dry-run reports the drift and writes nothing', function (): void {
    [, $id] = recountFixture(drift: -4);

    $this->artisan('vaulter:drives:recount', ['--drive' => [(string) $id], '--dry-run' => true])
        ->expectsOutputToContain('not corrected')
        ->assertSuccessful();

    expect(Drive::query()->findOrFail($id)->used_bytes)->toBe(12);
});

it('is idempotent and silent when the stored value is right', function (): void {
    [, $id] = recountFixture(drift: 7);

    $this->artisan('vaulter:drives:recount', ['--drive' => [(string) $id]])->assertSuccessful();
    $this->artisan('vaulter:drives:recount', ['--drive' => [(string) $id]])
        ->expectsOutputToContain('0 with drift')
        ->assertSuccessful();

    expect(Drive::query()->findOrFail($id)->used_bytes)->toBe(16);
});

it('touches only the drives it names', function (): void {
    [, $first] = recountFixture(drift: 50);
    [, $second] = recountFixture(drift: 50);

    $this->artisan('vaulter:drives:recount', ['--drive' => [(string) $first]])->assertSuccessful();

    expect(Drive::query()->findOrFail($first)->used_bytes)->toBe(16)
        ->and(Drive::query()->findOrFail($second)->used_bytes)->toBe(66);
});

it('recounts drives of every tenant as maintenance, whatever tenant the process runs in', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    [, $inA] = recountFixture(drift: 9);
    $resolver->tenant = 'B';
    [, $inB] = recountFixture(drift: 11);
    $resolver->tenant = 'C';

    $this->artisan('vaulter:drives:recount')->assertSuccessful();

    expect(Drive::query()->findOrFail($inA)->used_bytes)->toBe(16)
        ->and(Drive::query()->findOrFail($inB)->used_bytes)->toBe(16);
    MutableTenantResolver::clearFixtureStamps();
});
