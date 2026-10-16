<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;

// The fragment below is copied into README.md byte for byte; the README's own `use` lines are the
// imports of this file.
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('opens a drive and uploads a file (README quick example)', function (): void {
    $project = DriveTestHelpers::host();
    $user = DriveTestHelpers::actor(Vaulter::drive($project)->asSystem('recipe fixture'), 1);

    // [recipe:readme-quick-example]
    // $project is any Eloquent model; $user is the signed-in user.
    $drive = Vaulter::drive($project)->actingAs($user);

    $reports = $drive->folders()->create('Reports');
    $file = $drive->files()->upload(UploadSource::fromString('hello', 'hello.txt'), new UploadOptions(parent: $reports));
    // [/recipe]

    expect($file->name)->toBe('hello.txt');
});
