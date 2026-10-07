<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
});

it('reports zero, one and multiple swept sessions with the actual counts', function (): void {
    Artisan::call('vaulter:sweep-uploads');
    expect(Artisan::output())->toContain('Swept 0 stale upload sessions.');

    $storage = UploadTestHelpers::storage();
    $first = UploadTestHelpers::session($storage);
    $first->forceFill(['expires_at' => Carbon::now()->subHour()])->save();

    Artisan::call('vaulter:sweep-uploads');
    expect(Artisan::output())->toContain('Swept 1 stale upload session.');

    foreach (range(1, 2) as $number) {
        $session = UploadTestHelpers::session($storage, targetName: 'stale-'.$number);
        $session->forceFill(['expires_at' => Carbon::now()->subHour()])->save();
    }

    Artisan::call('vaulter:sweep-uploads');
    expect(Artisan::output())->toContain('Swept 2 stale upload sessions.');
});

it('applies the maintenance retention override and reports the actual subtree count', function (): void {
    $this->travelTo(Carbon::parse('2026-01-31 12:00:00'));
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(2)])->save();

    Artisan::call('vaulter:purge-trash', ['--days' => '1']);
    $output = Artisan::output();

    expect($output)->toContain('Purged 1 trashed subtree.')
        ->and(Node::withTrashed()->whereKey($node->getKey())->exists())->toBeFalse();
});
