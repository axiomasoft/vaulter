<?php

declare(strict_types=1);

use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\CreateFolderOptions;
use Vaulter\Drive\Data\FileStream;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\RevisionOptions;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;

// The signatures themselves are pinned by the committed surface manifests
// (packages/*/tests/Arch/__snapshots__/surface.json, tests/Repo/Arch/SurfaceManifestTest.php).
it('exposes the canonical operation families and keeps the value objects final and readonly', function (): void {
    expect(method_exists(DriveHandle::class, 'upload'))->toBeFalse()
        ->and(method_exists(DriveHandle::class, 'files'))->toBeTrue();

    foreach ([UploadOptions::class, RevisionOptions::class, CreateFolderOptions::class, MoveOptions::class, CopyOptions::class, RestoreOptions::class, ListOptions::class, SearchOptions::class, FileStream::class, UploadSource::class] as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue($class)->and($ref->isReadOnly())->toBeTrue($class);
    }
});
