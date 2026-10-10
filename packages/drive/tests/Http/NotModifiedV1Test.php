<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/**
 * GET nodes/{node} answers 304 only to a caller who may still see the node, and only
 * for the plain representation: an include is another variant and carries no ETag.
 */
uses(RefreshDatabase::class);

it('answers 304 with the validator headers and no body, and 404 once access is revoked', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'cached');
    $grant = AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $etag = $this->getJson(V1::url('nodes/'.$node->id))->assertOk()->headers->get('ETag');

    $notModified = $this->getJson(V1::url('nodes/'.$node->id), ['If-None-Match' => $etag, 'X-Request-Id' => 'cached-1'])
        ->assertStatus(304)
        ->assertHeader('ETag', $etag)
        ->assertHeader('X-Vaulter-Api-Version', '1')
        ->assertHeader('X-Request-Id', 'cached-1');
    expect($notModified->getContent())->toBe('')
        ->and($notModified->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($notModified->getVary())->toContain('Authorization', 'Cookie');

    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->whereKey($grant->getKey())->delete());

    $this->getJson(V1::url('nodes/'.$node->id), ['If-None-Match' => $etag])->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('answers 200 to a stale If-None-Match', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'renamed');
    $this->actingAs(V1::user(1));
    $etag = $this->getJson(V1::url('nodes/'.$node->id))->headers->get('ETag');
    $drive->nodes()->rename($node->id, 'renamed-again');

    $this->getJson(V1::url('nodes/'.$node->id), ['If-None-Match' => $etag])->assertOk()->assertJsonPath('data.name', 'renamed-again');
});

it('serves include=breadcrumb,children without an ETag and never as 304', function (): void {
    $drive = V1::drive(userId: 1);
    $parent = V1::folder($drive, 'parent');
    $child = V1::folder($drive, 'child', $parent->id);
    V1::folder($drive, 'grandchild', $child->id);
    $this->actingAs(V1::user(1));
    $etag = $this->getJson(V1::url('nodes/'.$child->id))->headers->get('ETag');

    $response = $this->getJson(V1::url('nodes/'.$child->id.'?include=breadcrumb,children'), ['If-None-Match' => $etag])
        ->assertOk()
        ->assertHeaderMissing('ETag')
        ->assertJsonPath('data.etag', $etag)
        ->assertJsonPath('data.breadcrumb.1.id', $parent->id)
        ->assertJsonPath('data.breadcrumb.2', ['id' => $child->id, 'name' => 'child'])
        ->assertJsonPath('data.children.data.0.name', 'grandchild')
        ->assertJsonPath('data.children.meta.next_cursor', null);
    expect($response->json('data.breadcrumb'))->toHaveCount(3)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->getJson(V1::url('nodes/'.$child->id.'?include=everything'))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
