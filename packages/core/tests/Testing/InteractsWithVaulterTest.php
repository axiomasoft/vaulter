<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Testing\Contracts\PermissionFixture;
use Vaulter\Testing\Fixtures\FixtureActor;
use Vaulter\Testing\InteractsWithVaulter;

uses(InteractsWithVaulter::class);

beforeEach(fn () => config()->set('vaulter.access.permission_driver', 'database'));

it('fakes the disks the profiles write to', function (): void {
    $disk = $this->vaulterFakeStorage();
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();

    Node::factory()->file('stored bytes')->in($drive)->create();

    expect($disk->allFiles())->not->toBeEmpty()
        ->and(Storage::disk($drive->disk)->allFiles())->not->toBeEmpty();
});

it('asserts the events the journal recorded', function (): void {
    Drive::factory()->for(DriveTestHelpers::host())->create();

    $this->assertVaulterEventRecorded(DriveEventType::DriveCreated);
    $this->assertVaulterEventRecorded(DriveEventType::DriveCreated, static fn ($row): bool => $row->drive_id !== null);
    $this->assertVaulterEventNotRecorded(DriveEventType::UploadAborted);
    expect(fn () => $this->assertVaulterEventRecorded(DriveEventType::UploadAborted))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->assertVaulterEventNotRecorded(DriveEventType::DriveCreated))->toThrow(AssertionFailedError::class);
});

it('judges visibility through Operations', function (): void {
    $fixture = PermissionFixture::build();
    $stranger = FixtureActor::next();

    $this->assertNodeVisibleTo($fixture->actor, $fixture->parent);
    $this->assertNodeNotVisibleTo($fixture->actor, $fixture->child);
    $this->assertNodeNotVisibleTo($stranger, $fixture->parent);
    $this->assertNodeVisibleTo($fixture->actor, (string) $fixture->parent->getKey());
    expect(fn () => $this->assertNodeVisibleTo($stranger, $fixture->parent))->toThrow(AssertionFailedError::class);
});

it('opens drives as an actor', function (): void {
    $fixture = PermissionFixture::build();
    $open = $this->vaulterActingAs($fixture->actor);

    $handle = $open($fixture->drive);

    expect($handle->actor()->isSystem())->toBeFalse()
        ->and($handle->nodes()->find($fixture->parent->getKey())->id)->toBe((string) $fixture->parent->getKey());
});
