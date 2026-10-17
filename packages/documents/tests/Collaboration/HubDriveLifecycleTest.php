<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Vaulter\Documents\Collaboration\CollaborationCall;
use Vaulter\Documents\Collaboration\CollaborationHub;
use Vaulter\Documents\Data\Collaboration\RoomRef;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Actions\Drives\ArchiveDriveAction;
use Vaulter\Drive\Actions\Drives\DeleteDriveAction;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;

it('refuses a store when the drive changes after the room was opened', function (DriveStatus $status, string $exception): void {
    Hub::configure();
    Queue::fake();
    $node = Hub::document();
    $call = new CollaborationCall(
        room: Hub::room($node),
        ref: new RoomRef(driveId: (string) $node->drive_id, nodeId: (string) $node->id, epoch: 0),
        subject: '1', mode: 'rw', schemaVersion: 1, tenant: null,
    );
    $hub = app(CollaborationHub::class);
    $room = $hub->open($call, $call->room);
    expect($room->driveActive)->toBeTrue();

    $action = $status === DriveStatus::Archived ? ArchiveDriveAction::class : DeleteDriveAction::class;
    app($action)->execute(DocumentTestHelpers::context($node));
    $before = Hub::aggregate($node)->getAttributes();
    $history = DocumentTestHelpers::revisions($node)->modelKeys();
    $sequence = $node->fresh()->sequence;
    Event::fake([DocumentContentUpdated::class]);

    expect(fn () => $hub->store(
        room: $room, call: $call, yjsState: 'late-state', content: Hub::tiptap('Late'), seed: false,
    ))->toThrow($exception)
        ->and(Hub::aggregate($node)->getAttributes())->toBe($before)
        ->and(DocumentTestHelpers::revisions($node)->modelKeys())->toBe($history)
        ->and($node->fresh()->sequence)->toBe($sequence);
    Event::assertNotDispatched(DocumentContentUpdated::class);
})->with([
    'archived' => [DriveStatus::Archived, PermissionDeniedException::class],
    'deleting' => [DriveStatus::Deleting, NodeNotFoundException::class],
]);
