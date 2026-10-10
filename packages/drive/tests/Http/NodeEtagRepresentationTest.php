<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/**
 * A node's tag validates the representation the caller receives, not just the node's
 * sequence: anything that changes what this caller sees (its abilities, its star)
 * changes its tag, and only its tag.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('gives the owner and a reader different tags for the same node', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::file($drive, 'shared.txt');
    AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);

    $this->actingAs(V1::user(1));
    $owner = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();
    $this->actingAs(V1::user(2));
    $reader = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();

    expect($owner->json('data.sequence'))->toBe($reader->json('data.sequence'))
        ->and($owner->headers->get('ETag'))->not->toBe($reader->headers->get('ETag'))
        ->and($reader->json('data.abilities.edit'))->toBeFalse();
});

it('changes the reader tag when a grant makes it a writer, though the node did not change', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::file($drive, 'promoted.txt');
    $grant = AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $before = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();

    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->whereKey($grant->getKey())->delete());
    AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::WRITER, subjectId: 2);
    $after = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();

    expect($after->json('data.sequence'))->toBe($before->json('data.sequence'))
        ->and($after->json('data.abilities.edit'))->toBeTrue()
        ->and($after->headers->get('ETag'))->not->toBe($before->headers->get('ETag'));
});

it('changes only the starring caller tag on star', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::file($drive, 'star.txt');
    AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $readerBefore = DriveV1::etag($this, $node->id);
    $this->actingAs(V1::user(1));
    $ownerBefore = DriveV1::etag($this, $node->id);

    $starred = $this->putJson(V1::url('nodes/'.$node->id.'/star'))->assertOk()->assertJsonPath('data.viewer.starred', true);

    expect($starred->headers->get('ETag'))->not->toBe($ownerBefore)
        ->and(DriveV1::etag($this, $node->id))->toBe($starred->headers->get('ETag'));
    $this->actingAs(V1::user(2));
    expect(DriveV1::etag($this, $node->id))->toBe($readerBefore);
});

it('puts the same tag in the body and in the ETag header', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'same');
    $this->actingAs(V1::user(1));

    $response = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();

    expect($response->json('data.etag'))->toBe($response->headers->get('ETag'))
        ->and($response->json('data.etag'))->toStartWith('"'.$node->id.'.'.$response->json('data.sequence').'.');
});
