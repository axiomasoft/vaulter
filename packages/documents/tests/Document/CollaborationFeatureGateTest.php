<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/*
 * VLT-REMED P3.1 (F-P1.6-01, D13/D20) — realtime collaboration is now gated by its
 * OWN feature flag ('features.collaboration', default false), separate from
 * 'document_versions' (the non-realtime editing/versions gate). Red-before-fix
 * (D7): before this item, 'document_versions' alone was enough to mount
 * the session + hub authenticate/load/store routes, so "collaboration=false,
 * document_versions=true" 404'd nowhere — the item-gate assertions below FAILED
 * on the pre-fix code (the hub routes 2xx'd/401'd through ResolveCollaborationCall
 * instead of 404ing before it ever ran). The session route stays registered and its
 * operation refuses with 409 collaboration_unavailable.
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

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('Draft'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

it('the collaboration session is refused when collaboration is off, F-P1.6-01', function (): void {
    expect(config('vaulter-documents.collaboration.enabled'))->toBeFalse();

    $node = collabGateDocumentNode();

    // The route is always registered; the operation refuses a node the caller can see.
    $this->postJson(Docs::url('documents/'.$node->getKey().'/collaboration/session'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_unavailable');
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
    $nodeId = (string) $node->getKey();

    $this->getJson(Docs::url('documents/'.$nodeId.'/content'))->assertOk();

    Docs::put($nodeId, Docs::doc('Autosaved'))->assertOk();
});
