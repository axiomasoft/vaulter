<?php

declare(strict_types=1);

use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Tests\Fixtures\Collaboration\AcmeDriver;
use Vaulter\Documents\Tests\Fixtures\Collaboration\WithAcme;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Kernel;

uses(WithAcme::class);

beforeEach(function (): void {
    expect(app(Kernel::class)->reasons())->toBe([]);
    Granted::configure();
});

it('issues a neutral opaque session without the commercial module and never caches it', function (): void {
    $node = Granted::document(type: 'acme/ot');
    $this->actingAs(AccessTestHelpers::user(1));
    $oldTag = Docs::etag((string) $node->getKey());
    $before = Granted::aggregate($node);
    $url = Docs::url('documents/'.$node->getKey().'/collaboration/session');
    $response = $this->postJson($url)
        ->assertCreated()->assertJsonPath('data.protocol', 'acme-ot')->assertJsonPath('data.version', 'acme-ot/1')
        ->assertJsonPath('data.mode', 'write')->assertJsonPath('data.endpoint', null)
        ->assertJsonPath('data.generation', 1)->assertJsonPath('data.schema_version', 1)
        ->assertJsonPath('data.node_id', (string) $node->getKey());
    expect(array_keys($response->json('data')))->toBe(['protocol', 'version', 'endpoint', 'token', 'expires_at', 'mode', 'node_id', 'generation', 'schema_version', 'options'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(app(Kernel::class)->isActive('collaboration'))->toBeFalse();
    $grant = app(AcmeDriver::class)->verify($response->json('data.token'));
    expect($grant->principal->id)->toBe(1)->and($grant->nodeId)->toBe((string) $node->getKey());
    $after = Granted::aggregate($node);
    expect($after->write_mode)->toBe(DocumentWriteMode::Journal)
        ->and($after->state_version)->toBe($before->state_version + 1)
        ->and($after->collab_epoch)->toBe($before->collab_epoch + 1)
        ->and($after->payload?->content)->toBe($before->payload?->content);

    Docs::put((string) $node->getKey(), ['columns' => []], $oldTag)
        ->assertConflict()->assertJsonPath('code', 'document_command_required');
    $this->postJson($url)->assertCreated()->assertJsonPath('data.generation', $after->collab_epoch);
    expect(Granted::aggregate($node)->state_version)->toBe($after->state_version);
});

it('narrows readers and mismatching client schemas without changing the stored schema', function (?int $schema, string $role): void {
    $node = Granted::document(level: $role, type: 'acme/ot');
    $this->actingAs(AccessTestHelpers::user(1));
    $this->postJson(Docs::url('documents/'.$node->getKey().'/collaboration/session'), ['schema_version' => $schema])
        ->assertCreated()->assertJsonPath('data.mode', 'read')->assertJsonPath('data.schema_version', 1)
        ->assertJsonPath('data.generation', 0);
    expect(Granted::aggregate($node)->write_mode)->toBe(DocumentWriteMode::Snapshot)
        ->and(Granted::aggregate($node)->state_version)->toBe(1);
})->with([[null, RoleRegistry::READER], [0, RoleRegistry::WRITER], [2, RoleRegistry::WRITER]]);

it('validates the request and maps an invalid driver session to a safe problem', function (): void {
    $node = Granted::document(type: 'acme/ot');
    $this->actingAs(AccessTestHelpers::user(1));
    $url = Docs::url('documents/'.$node->getKey().'/collaboration/session');
    $this->postJson($url, ['schema_version' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('schema_version');
    app(AcmeDriver::class)->invalidVersion = 'acme-ot/999';
    $this->postJson($url)->assertStatus(503)->assertJsonPath('code', 'collaboration_protocol_contract')->assertJsonMissingPath('data.token');
});

it('checks View before disclosing a missing driver or the node kind', function (): void {
    $node = Granted::document(level: null);
    $this->actingAs(AccessTestHelpers::user(1));
    $url = Docs::url('documents/'.$node->getKey().'/collaboration/session');
    $this->postJson($url)->assertNotFound()->assertJsonPath('code', 'node_not_found');
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 1);
    $this->postJson($url)->assertStatus(409)->assertJsonPath('code', 'collaboration_unavailable');
    $folder = AccessTestHelpers::folder($node->storage()->firstOrFail(), null, 'Folder');
    $url = Docs::url('documents/'.$folder->getKey().'/collaboration/session');
    $this->postJson($url)->assertNotFound();
    AccessTestHelpers::grant($folder, RoleRegistry::READER, subjectId: 1);
    $this->postJson($url)->assertStatus(409)->assertJsonPath('code', 'not_a_document');
});

it('serializes empty protocol options as an object', function (): void {
    $node = Granted::document(type: 'acme/ot');
    $this->actingAs(AccessTestHelpers::user(1));
    app(AcmeDriver::class)->emptyOptions = true;
    $response = $this->postJson(Docs::url('documents/'.$node->getKey().'/collaboration/session'))->assertCreated();
    expect(json_decode($response->getContent())->data->options)->toBeInstanceOf(stdClass::class);
});
