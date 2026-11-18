<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Kernel;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Doctor\BlobIntegrityCheck;
use Vaulter\Media\Doctor\BlobPinsCheck;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

it('boots core and media providers and commits reads and pins real CAS content', function (): void {
    Storage::fake('local');
    $kernel = app(Kernel::class);
    expect($kernel->isValid())->toBeTrue()
        ->and($kernel->isActive('media'))->toBeTrue()
        ->and(app(ContentStore::class))->toBeInstanceOf(ContentStore::class)
        ->and(app(StorageDriver::class))->toBeInstanceOf(StorageDriver::class);

    $registry = app(PluginInspector::class);
    $checks = $registry->slotContributions()[DoctorCheck::class];
    expect(array_map(static fn (SlotContribution $check): string => $check->implementation, $checks))
        ->toContain(BlobIntegrityCheck::class, BlobPinsCheck::class);

    $store = app(BlobStore::class);
    $input = CasTestHelpers::streamFor('external consumer content');
    try {
        $blob = $store->commit($input, BlobScope::tenant('consumer'));
    } finally {
        fclose($input);
    }
    $stream = $store->read($blob);
    expect(is_resource($stream))->toBeTrue();
    try {
        expect(stream_get_contents($stream))->toBe('external consumer content');
    } finally {
        fclose($stream);
    }

    app(VaulterDatabase::class)->transaction(static fn () => app(BlobPins::class)->pin($blob->id, 'consumer.asset', 'asset-1'));
    expect(app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('blob_references'))->where('blob_id', $blob->id)->count())->toBe(1);
});
