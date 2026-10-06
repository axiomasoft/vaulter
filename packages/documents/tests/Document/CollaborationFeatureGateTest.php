<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\TestUser;

/*
 * VLT-REMED P3.1 (F-P1.6-01, D13/D20) — realtime collaboration is now gated by its
 * OWN feature flag ('features.collaboration', default false), separate from
 * 'document_versions' (the non-realtime editing/versions gate). Red-before-fix
 * (D7): before this item, 'document_versions' alone was enough to mount
 * collab/token + collab/authenticate + collab/store, so "collaboration=false,
 * document_versions=true" 404'd nowhere — the item-gate assertions below FAILED
 * on the pre-fix code (all three collab routes 2xx'd/401'd through
 * ResolveCollaborationCall instead of 404ing before it ever ran).
 *
 * This suite's base TestCase (DocumentTestCase) already turns 'document_versions'
 * ON; it deliberately does NOT turn 'collaboration' on, so every test here runs
 * against the true default (both flags independent, collaboration OFF).
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function collabGateDocumentNode(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('Draft'),
        parent: $drive->root(),
    ));
}

it('collab/token 404s when collaboration is off even though document_versions is on, F-P1.6-01', function (): void {
    expect(config('vaulter-documents.collaboration.enabled'))->toBeFalse();

    $node = collabGateDocumentNode();

    $this->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertNotFound();
});

it('collab/store 404s (route not mounted) when collaboration is off, F-P1.6-01', function (): void {
    $node = collabGateDocumentNode();

    $this->postJson('api/vaulter/collab/store', [
        'room' => 'vaulter.documents.'.$node->getKey(),
        'node_id' => (string) $node->getKey(),
        'yjs_state' => base64_encode('anything'),
    ])->assertNotFound();
});

it('collab/authenticate 404s (route not mounted) when collaboration is off, F-P1.6-01', function (): void {
    $node = collabGateDocumentNode();

    $this->postJson('api/vaulter/collab/authenticate', [
        'room' => 'vaulter.documents.'.$node->getKey(),
    ])->assertNotFound();
});

it('base (non-realtime) content editing keeps working with collaboration off, F-P1.6-01', function (): void {
    $node = collabGateDocumentNode();
    $nodeBase = 'api/vaulter/nodes/'.$node->getKey();

    $this->getJson($nodeBase.'/content')->assertOk();

    $this->patchJson($nodeBase.'/content', [
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Autosaved']]],
        ]],
    ])->assertOk();
});
