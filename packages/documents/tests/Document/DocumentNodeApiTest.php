<?php

declare(strict_types=1);

use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Fixtures\Access\ProfileCreateGate;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The Documents REST surface: create a document, read/autosave its
 * content, snapshot + restore revisions and export — end to end through the v1 API. Runs
 * open (the null driver), so a bare authenticated actor suffices.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function documentStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('runs the document cycle: create → content → autosave → revision → restore → export', function (): void {
    $storage = documentStorage();

    // create a document with initial content
    $nodeId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/documents'), [
        'name' => 'Spec',
        'content' => Docs::doc('Initial'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'document')
        ->assertJsonPath('data.presentation.handler', null)
        ->assertJsonPath('data.presentation.document_type', 'vaulter/rich-text')
        ->assertJsonPath('data.presentation.type_available', true)
        ->assertJsonPath('data.presentation.viewer.key', 'vaulter/tiptap')
        ->assertJsonPath('data.presentation.capabilities.editable', true)
        ->json('data.id');
    $content = Docs::url('documents/'.$nodeId.'/content');

    // read the head content
    $this->getJson($content)
        ->assertOk()
        ->assertJsonPath('data.type', 'vaulter/rich-text')
        ->assertJsonPath('data.state_version', 1)
        ->assertJsonPath('data.content.content.0.content.0.text', 'Initial');

    // autosave new content
    Docs::put($nodeId, Docs::doc('Autosaved'))
        ->assertOk()
        ->assertJsonPath('data.state_version', 2)
        ->assertJsonPath('data.content.content.0.content.0.text', 'Autosaved');

    // checkpoint a named revision
    $revisionId = $this->postJson(Docs::url('documents/'.$nodeId.'/revisions'), ['label' => 'Review'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Review')
        ->assertJsonPath('data.is_head', true)
        ->json('data.id');

    // history lists the revisions
    $this->getJson(Docs::url('documents/'.$nodeId.'/revisions'))
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'number', 'is_autosave', 'is_head']], 'meta' => ['next_cursor', 'per_page']]);

    // one revision with its content
    $this->getJson(Docs::url('documents/'.$nodeId.'/revisions/'.$revisionId))
        ->assertOk()
        ->assertJsonPath('data.id', $revisionId)
        ->assertJsonPath('data.content.content.0.content.0.text', 'Autosaved');

    // restore the named revision
    Docs::put($nodeId, Docs::doc('Later'))->assertOk();
    $this->postJson(Docs::url('documents/'.$nodeId.'/revisions/'.$revisionId.'/restore'), [], Docs::ifMatch($nodeId))
        ->assertOk()
        ->assertJsonPath('data.content.content.0.content.0.text', 'Autosaved');

    // export to html + markdown
    $this->get(Docs::url('documents/'.$nodeId.'/export/html'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename=Spec.html');

    $this->get(Docs::url('documents/'.$nodeId.'/export/markdown'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
});

it('rejects a content write on a non-document node', function (): void {
    $storage = documentStorage();
    $folderId = $this->postJson(Docs::url('drives/'.$storage->getKey().'/folders'), ['name' => 'Plain'])
        ->assertCreated()
        ->json('data.id');

    $this->putJson(Docs::url('documents/'.$folderId.'/content'), ['content' => ['type' => 'doc', 'content' => []]], ['If-Match' => '"any"'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_a_document');
});

it('rejects invalid document-create boundaries without persisting a node', function (): void {
    $storage = documentStorage();
    $url = Docs::url('drives/'.$storage->getKey().'/documents');

    $cases = [
        'missing name' => [[], 'name'],
        'non-string name' => [['name' => ['Spec']], 'name'],
        'malformed type key' => [['name' => 'Spec', 'type' => 'pdf'], 'type'],
        'non-array content' => [['name' => 'Spec', 'content' => 'plain text'], 'content'],
    ];

    foreach ($cases as $case => [$payload, $field]) {
        $this->postJson($url, $payload)
            ->assertUnprocessable("{$case} should fail validation")
            ->assertJsonValidationErrors($field);
    }

    $this->postJson($url, ['name' => 'Spec', 'type' => 'acme/unregistered'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'unknown_document_type');
    $this->postJson($url, ['name' => 'Spec', 'content' => ['type' => 'paragraph']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_document_content');
});

it('rejects invalid autosave boundaries without changing the current state', function (): void {
    $storage = documentStorage();
    $nodeId = Docs::create($storage->getKey(), ['content' => ['type' => 'doc', 'content' => []]]);
    $url = Docs::url('documents/'.$nodeId.'/content');
    $tag = Docs::etag($nodeId);

    $cases = [
        'missing content' => [[], 'content'],
        'non-array content' => [['content' => 'plain text'], 'content'],
    ];

    foreach ($cases as $case => [$payload, $field]) {
        $this->putJson($url, $payload, ['If-Match' => $tag])
            ->assertUnprocessable("{$case} should fail validation")
            ->assertJsonValidationErrors($field);
    }
    $this->putJson($url, ['content' => ['type' => 'paragraph']], ['If-Match' => $tag])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_document_content');

    $this->getJson($url)
        ->assertOk()
        ->assertHeader('ETag', $tag)
        ->assertJsonPath('data.state_version', 1);
});

it('enforces revision label and boolean boundaries', function (): void {
    $storage = documentStorage();
    $nodeId = Docs::create($storage->getKey(), ['content' => ['type' => 'doc', 'content' => []]]);
    $url = Docs::url('documents/'.$nodeId.'/revisions');

    $this->postJson($url, ['label' => str_repeat('x', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');
    $this->postJson($url, ['label' => 'Review', 'keep_forever' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep_forever');

    $this->postJson($url, ['label' => str_repeat('x', 255), 'keep_forever' => true])
        ->assertCreated()
        ->assertJsonPath('data.label', str_repeat('x', 255))
        ->assertJsonPath('data.keep_forever', true);
    $this->postJson($url)->assertCreated()->assertJsonPath('data.label', null);
});

it('gates document creation through the profile driver', function (bool $allow): void {
    $drive = documentStorage();
    ProfileCreateGate::install($allow);
    $this->postJson(Docs::url('drives/'.$drive->id.'/documents'), ['name' => 'profile-doc'])
        ->assertStatus($allow ? 201 : 403);
})->with([true, false]);

it('refuses a lost autosave when the tag the client read is stale', function (): void {
    $storage = documentStorage();
    $nodeId = Docs::create($storage->getKey());
    $url = Docs::url('documents/'.$nodeId.'/content');
    $read = $this->getJson($url)->assertOk()->json('data.state_version');
    $stale = Docs::etag($nodeId);

    // Another tab saves first.
    Docs::put($nodeId, Docs::doc('other tab'), $stale)
        ->assertOk()
        ->assertJsonPath('data.state_version', $read + 1);

    // The stale save is refused and changes nothing.
    Docs::put($nodeId, Docs::doc('stale tab'), $stale)
        ->assertStatus(412)
        ->assertJsonPath('code', 'document_state_mismatch')
        ->assertJsonPath('meta.node_id', $nodeId)
        ->assertJsonMissingPath('meta.context');
    $this->getJson($url)
        ->assertJsonPath('data.state_version', $read + 1)
        ->assertJsonPath('data.content.content.0.content.0.text', 'other tab');

    // Without If-Match the save does not run at all.
    $this->putJson($url, ['content' => Docs::doc('no precondition')])->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    Docs::put($nodeId, Docs::doc('fresh'))->assertOk();
});
