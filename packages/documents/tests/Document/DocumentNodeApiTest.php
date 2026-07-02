<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The DocDrive REST surface (§5 / §7 / M4a): create a document node, read/autosave
 * its content, snapshot + restore versions, resolve the handler descriptor and
 * export — end to end through the API. Runs open (the null driver), so a bare
 * authenticated actor suffices.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function documentStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

it('runs the document node cycle: create → content → autosave → version → restore → handler → export', function (): void {
    $storage = documentStorage();
    $base = 'api/vaulter/storages/'.$storage->getKey();

    // create a document node with initial content
    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Spec',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Initial']]],
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'document')
        ->assertJsonPath('data.handler.id', 'tiptap')
        ->json('data.id');

    $nodeBase = 'api/vaulter/nodes/'.$nodeId;

    // read the head content
    $this->getJson($nodeBase.'/content')
        ->assertOk()
        ->assertJsonPath('data.version_no', 1);

    // autosave new content
    $this->patchJson($nodeBase.'/content', [
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Autosaved']]],
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('data.is_autosave', true);

    // snapshot a named version
    $versionId = $this->postJson($nodeBase.'/document-versions', ['label' => 'Review'])
        ->assertCreated()
        ->assertJsonPath('data.snapshot_label', 'Review')
        ->json('data.id');

    // history lists the versions
    $this->getJson($nodeBase.'/document-versions')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'version_no', 'is_autosave']]]);

    // restore the named version
    $this->postJson($nodeBase.'/document-versions/'.$versionId.'/restore')
        ->assertCreated()
        ->assertJsonPath('data.is_autosave', false);

    // the handler descriptor
    $this->getJson($nodeBase.'/handler')
        ->assertOk()
        ->assertJsonPath('data.id', 'tiptap')
        ->assertJsonPath('data.capabilities.editable', true);

    // export to html + markdown
    $this->getJson($nodeBase.'/export?format=html')
        ->assertOk()
        ->assertJsonPath('data.format', 'html')
        ->assertJsonPath('data.mime_type', 'text/html');

    $this->getJson($nodeBase.'/export?format=markdown')
        ->assertOk()
        ->assertJsonPath('data.format', 'markdown');
});

it('rejects a content write on a non-document node', function (): void {
    $storage = documentStorage();
    $folderId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/folders', ['name' => 'Plain'])
        ->assertCreated()
        ->json('data.id');

    $this->patchJson('api/vaulter/nodes/'.$folderId.'/content', [
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(500); // NotADocumentNodeException (no domain HTTP handler in test app)
});
