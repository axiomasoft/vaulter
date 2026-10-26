<?php

declare(strict_types=1);

use Vaulter\Collaboration\Tests\Support\DocumentsV1 as Docs;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Kernel;
use Vaulter\Testing\Fixtures\TestUser;

/*
 * Realtime collaboration is an opt-in module (D22): `vaulter.modules.collaboration` is false by default, and the
 * module's session and hub routes are not mounted then (404). This suite's base TestCase leaves the module off, so
 * every test here runs against the true default of a host that installed the package and did not switch it on.
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

it('the collaboration module is off by default', function (): void {
    expect(config('vaulter.modules.collaboration'))->toBeNull()
        ->and(app(Kernel::class)->isActive('collaboration'))->toBeFalse();
});

it('the collaboration session route answers 404 when the module is off', function (): void {
    $node = collabGateDocumentNode();

    $this->postJson(Docs::url('documents/'.$node->getKey().'/collaboration/session'))->assertNotFound();
});

it('the hub routes answer 404 when the module is off', function (string $endpoint): void {
    $node = collabGateDocumentNode();

    $this->postJson(Docs::url('collaboration/hub/'.$endpoint), [
        'room' => 'vaulter.documents.'.$node->getKey(),
        'state' => base64_encode('anything'),
    ])->assertNotFound();
})->with(['authenticate', 'load', 'append']);

it('base (non-realtime) content editing keeps working with the collaboration module off', function (): void {
    $node = collabGateDocumentNode();
    $presentation = app(DocumentPresenter::class)->present($node);
    expect($presentation->capabilities->editable)->toBeTrue()
        ->and($presentation->capabilities->collaborative)->toBeFalse();
    $nodeId = (string) $node->getKey();

    $this->getJson(Docs::url('documents/'.$nodeId.'/content'))->assertOk();

    Docs::put($nodeId, Docs::doc('Autosaved'))->assertOk();
});
