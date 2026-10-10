<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/** Personal stars and namespaced properties over v1. */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
});

it('stars and unstars idempotently without touching the sequence, and lists the caller stars', function (): void {
    $node = V1::folder($this->drive, 'fav');
    $other = V1::folder($this->drive, 'other');
    $this->actingAs(V1::user(1));
    $sequence = DriveTestHelpers::model($node)->sequence;

    $this->putJson(V1::url('nodes/'.$node->id.'/star'))->assertOk()->assertJsonPath('data.viewer.starred', true);
    $this->putJson(V1::url('nodes/'.$node->id.'/star'))->assertOk()->assertJsonPath('data.viewer.starred', true);
    expect(DriveTestHelpers::model($node)->sequence)->toBe($sequence);

    $this->getJson(V1::url('drives/'.$this->driveId.'/starred'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $node->id);

    $this->deleteJson(V1::url('nodes/'.$node->id.'/star'))->assertOk()->assertJsonPath('data.viewer.starred', false);
    $this->getJson(V1::url('drives/'.$this->driveId.'/starred'))->assertOk()->assertJsonCount(0, 'data');
    expect($other->id)->not->toBe($node->id);
});

it('lets a reader star but not change properties', function (): void {
    $node = V1::folder($this->drive, 'read-only');
    AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));

    $this->putJson(V1::url('nodes/'.$node->id.'/star'))->assertOk();
    $this->patchJson(V1::url('nodes/'.$node->id.'/properties'), ['namespace' => 'acme', 'set' => ['k' => 'v']], ['If-Match' => DriveV1::etag($this, $node->id)])
        ->assertForbidden();
});

it('patches one property namespace with If-Match and returns it as a JSON object', function (): void {
    $node = V1::folder($this->drive, 'props');
    $this->actingAs(V1::user(1));
    $this->getJson(V1::url('nodes/'.$node->id))->assertOk()->assertJsonPath('data.properties', []);
    expect($this->getJson(V1::url('nodes/'.$node->id))->getContent())->toContain('"properties":{}');

    $set = $this->patchJson(V1::url('nodes/'.$node->id.'/properties'), ['namespace' => 'acme', 'set' => ['color' => 'red', 'size' => 'L']], ['If-Match' => DriveV1::etag($this, $node->id)])
        ->assertOk()
        ->assertJsonPath('data.properties.acme.color', 'red');

    $this->patchJson(V1::url('nodes/'.$node->id.'/properties'), ['namespace' => 'acme', 'unset' => ['size']], ['If-Match' => $set->headers->get('ETag')])
        ->assertOk()
        ->assertJsonPath('data.properties.acme', ['color' => 'red']);

    $this->patchJson(V1::url('nodes/'.$node->id.'/properties'), ['namespace' => 'vaulter', 'set' => ['x' => 'y']], ['If-Match' => DriveV1::etag($this, $node->id)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_properties');
});
