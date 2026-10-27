<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Testing\Contracts\StorageDriverContractTests;

uses(StorageDriverContractTests::class);

it('keeps the storage contract on isolated default and explicit local disks', function (?string $disk): void {
    foreach (['kit-default', 'kit-explicit'] as $name) {
        $root = Storage::fake($name)->path('');
        // Storage::fake adds a URL callback; build a real unsigned local disk on its isolated root.
        Storage::set($name, Storage::build(['driver' => 'local', 'root' => $root, 'serve' => false]));
    }
    config()->set('vaulter-media.disk', 'kit-default');
    $driver = app(FlysystemStorageDriver::class);
    expect($driver->disk($disk))->toBe(Storage::disk($disk ?? 'kit-default'));
    $this->assertStorageDriverContract($driver, $disk);
    expect(Storage::disk('kit-default')->allFiles())->toBe([])
        ->and(Storage::disk('kit-explicit')->allFiles())->toBe([]);
})->with(['default' => null, 'explicit' => 'kit-explicit']);
