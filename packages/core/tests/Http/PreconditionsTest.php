<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Tests\Support\NodeProbeRoutes;

/**
 * If-Match on a mutating route (the test probe renames through the real
 * NodeOperations): required, never `*`, strong comparison against the caller's current
 * representation, and the expected sequence handed to the operation.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    NodeProbeRoutes::register();
    $this->drive = V1::drive();
    $this->node = V1::folder($this->drive, 'before');
    $this->actingAs(V1::user());
    $this->etag = $this->getJson(NodeProbeRoutes::path($this->node->id))->assertOk()->headers->get('ETag');
});

it('answers 428 precondition_required without If-Match and changes nothing', function (): void {
    $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'])
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.header', 'If-Match');

    expect($this->drive->nodes()->find($this->node->id)->name)->toBe('before');
});

it('refuses If-Match: * with 428 wildcard_not_allowed', function (): void {
    $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'], ['If-Match' => '*'])
        ->assertStatus(428)
        ->assertJsonPath('meta.reason', 'wildcard_not_allowed');
});

it('never matches a weak tag (strong comparison) → 412', function (): void {
    $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'], ['If-Match' => 'W/'.$this->etag])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch')
        ->assertJsonPath('meta.node_id', $this->node->id);
});

it('applies the change with the current tag and returns a new one', function (): void {
    $response = $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'], ['If-Match' => $this->etag])
        ->assertOk()
        ->assertJsonPath('data.name', 'after');

    expect($response->headers->get('ETag'))->not->toBe($this->etag)
        ->toBe($response->json('data.etag'))
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('matches any tag of a list', function (): void {
    $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'], ['If-Match' => '"x", '.$this->etag])
        ->assertOk();
});

it('refuses a stale tag after a change the caller did not see, without revealing the current tag', function (): void {
    $this->drive->nodes()->rename($this->node->id, 'renamed elsewhere');

    $response = $this->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'], ['If-Match' => $this->etag])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch');

    expect($response->headers->has('ETag'))->toBeFalse()
        ->and($response->getContent())->not->toContain('"etag"')
        ->and($this->drive->nodes()->find($this->node->id)->name)->toBe('renamed elsewhere');
});

it('answers 404 before any precondition for a caller who cannot see the node', function (): void {
    $this->actingAs(V1::user(9))
        ->patchJson(NodeProbeRoutes::path($this->node->id), ['name' => 'after'])
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
});
