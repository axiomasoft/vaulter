<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;
use Vaulter\Tests\Support\NodeProbeRoutes;

/**
 * 304 is an answer about a representation the caller may still see: the node is
 * found (and authorized) before If-None-Match is compared, so a revoked reader with a
 * cached tag gets 404, not 304.
 */
uses(RefreshDatabase::class);

it('answers 304 to a matching If-None-Match, and 404 once the reader lost access', function (): void {
    NodeProbeRoutes::register();
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'cached');
    $grant = AccessTestHelpers::grant(DriveTestHelpers::model($node), RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));

    $etag = $this->getJson(NodeProbeRoutes::path($node->id), ['X-Request-Id' => 'first'])->assertOk()->headers->get('ETag');

    $notModified = $this->getJson(NodeProbeRoutes::path($node->id), ['If-None-Match' => $etag, 'X-Request-Id' => 'second'])
        ->assertStatus(304)
        ->assertHeader('ETag', $etag)
        ->assertHeader('X-Vaulter-Api-Version', '1')
        ->assertHeader('X-Request-Id', 'second');
    expect($notModified->getContent())->toBe('')
        ->and($notModified->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($notModified->getVary())->toContain('Authorization', 'Cookie');

    // A weak form of the same tag also matches (weak comparison).
    $this->getJson(NodeProbeRoutes::path($node->id), ['If-None-Match' => 'W/'.$etag])->assertStatus(304);

    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->whereKey($grant->getKey())->delete());

    $this->getJson(NodeProbeRoutes::path($node->id), ['If-None-Match' => $etag])
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
});

it('answers 200 with the new tag once the caller representation changed', function (): void {
    NodeProbeRoutes::register();
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'starred');
    $this->actingAs(V1::user());

    $etag = $this->getJson(NodeProbeRoutes::path($node->id))->headers->get('ETag');
    $drive->actingAs(V1::user())->nodes()->star($node->id);

    $response = $this->getJson(NodeProbeRoutes::path($node->id), ['If-None-Match' => $etag])->assertOk();

    expect($response->headers->get('ETag'))->not->toBe($etag)
        ->and($response->json('data.viewer.starred'))->toBeTrue();
});
