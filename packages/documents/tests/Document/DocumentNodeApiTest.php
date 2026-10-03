<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The Documents REST surface (§5 / §7 / M4a): create a document node, read/autosave
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
    ])->assertStatus(409)->assertJsonPath('code', 'not_a_document');
});

it('rejects invalid document-create boundaries without persisting a node', function (): void {
    $storage = documentStorage();
    $url = 'api/vaulter/storages/'.$storage->getKey().'/documents';

    $cases = [
        'missing name' => [[], 'name'],
        'non-string name' => [['name' => ['Spec']], 'name'],
        'unknown format' => [['name' => 'Spec', 'format' => 'pdf'], 'format'],
        'non-string mime' => [['name' => 'Spec', 'mime' => ['text/plain']], 'mime'],
        'non-editable mime' => [['name' => 'Spec', 'mime' => 'application/pdf'], 'mime'],
        'non-array content' => [['name' => 'Spec', 'content' => 'plain text'], 'content'],
        'non-document rich-text root' => [['name' => 'Spec', 'content' => ['type' => 'paragraph']], 'content.type'],
    ];

    foreach ($cases as $case => [$payload, $field]) {
        $this->postJson($url, $payload)
            ->assertUnprocessable("{$case} should fail validation")
            ->assertJsonValidationErrors($field);
    }
});

it('rejects invalid autosave boundaries without changing the current version', function (): void {
    $storage = documentStorage();
    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Spec',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');
    $url = 'api/vaulter/nodes/'.$nodeId.'/content';

    $cases = [
        'missing content' => [[], 'content'],
        'non-array content' => [['content' => 'plain text'], 'content'],
        'non-document rich-text root' => [['content' => ['type' => 'paragraph']], 'content.type'],
        'non-string Yjs state' => [['content' => ['type' => 'doc'], 'yjs_state' => []], 'yjs_state'],
    ];

    foreach ($cases as $case => [$payload, $field]) {
        $this->patchJson($url, $payload)
            ->assertUnprocessable("{$case} should fail validation")
            ->assertJsonValidationErrors($field);
    }

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.version_no', 1);
});

it('enforces named-version label and boolean boundaries', function (): void {
    $storage = documentStorage();
    $nodeId = $this->postJson('api/vaulter/storages/'.$storage->getKey().'/documents', [
        'name' => 'Spec',
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertCreated()->json('data.id');
    $url = 'api/vaulter/nodes/'.$nodeId.'/document-versions';

    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('label');
    $this->postJson($url, ['label' => str_repeat('x', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');
    $this->postJson($url, ['label' => 'Review', 'keep_forever' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep_forever');

    $this->postJson($url, ['label' => str_repeat('x', 255), 'keep_forever' => true])
        ->assertCreated()
        ->assertJsonPath('data.snapshot_label', str_repeat('x', 255))
        ->assertJsonPath('data.keep_forever', true);
});
