<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Эндпоинт connect-рукопожатия M4b (мастер-план §7): POST nodes/{node}/collab/token.
 * Авторизация идёт через AccessResolver — без View/Comment 403; commenter получает
 * read-only сессию, writer — writable. Токен НИКОГДА не выдаёт больше, чем способность
 * актора: read_only кодируется из права на узле, а не из запроса.
 */
beforeEach(function (): void {
    // Реальная выдача прав из грантов (база), а не открытый null-драйвер.
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
});

function grantedDocumentNode(?string $level, int $actorId = 1): Node
{
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('Draft'),
        parent: $drive->root(),
    ));

    if ($level !== null) {
        AccessTestHelpers::grant($node, $level, subjectId: $actorId);
    }

    return $node;
}

function actAsCollaborator(int $id = 1): TestUser
{
    $user = (new TestUser)->forceFill(['id' => $id]);
    test()->actingAs($user);

    return $user;
}

it('403s an actor with no grant on the document node', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: null);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertForbidden();
});

it('issues a read-only session for a commenter (no edit)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::COMMENTER);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.driver', 'hocuspocus')
        ->assertJsonPath('data.read_only', true)
        ->assertJsonPath('data.node_id', $node->getKey())
        ->assertJsonPath('data.room', 'vaulter.documents.'.$node->getKey());
});

it('issues a read-only session for a bare reader', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::READER);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.read_only', true);
});

it('issues a writable session for a writer (edit)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::WRITER);

    $response = test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.connection', null);

    $claims = JWT::decode($response->json('data.token'), new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));
    expect($claims->room)->toBe('vaulter.documents.'.$node->getKey())
        ->and($claims->readOnly)->toBeFalse();
});

it('returns a hocuspocus descriptor with url + JWT when that driver is configured', function (): void {
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
    config()->set('vaulter-documents.collaboration.hub.url', 'ws://hub:1234');

    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::WRITER);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.driver', 'hocuspocus')
        ->assertJsonPath('data.url', 'ws://hub:1234')
        ->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.connection', null);
});

it('409s when the target node is a folder, not a document', function (): void {
    actAsCollaborator();
    $drive = DocumentTestHelpers::drive();
    $folder = AccessTestHelpers::folder($drive->model(), null, 'plain-folder');
    AccessTestHelpers::grant($folder, RoleRegistry::WRITER);

    // A folder has no document payload — collab is document-only.
    test()->postJson('api/vaulter/nodes/'.$folder->getKey().'/collab/token')
        ->assertStatus(409)->assertJsonPath('code', 'not_a_document');
});
