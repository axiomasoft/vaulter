<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Models\Drive\Node;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Эндпоинт connect-рукопожатия M4b (мастер-план §7): POST documents/{node}/collaboration/session.
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

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('Draft'),
        parent: DriveTestHelpers::model($drive->root()),
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

it('hides the document node from an actor with no grant (node_not_found)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: null);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session')
        ->assertNotFound()
        ->assertJsonPath('code', 'node_not_found');
});

it('issues a read-only session for a commenter (no edit)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::COMMENTER);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session')
        ->assertCreated()
        ->assertJsonPath('data.driver', 'hocuspocus')
        ->assertJsonPath('data.mode', 'ro')
        ->assertJsonPath('data.node_id', (string) $node->getKey())
        ->assertJsonPath('data.drive_id', (string) $node->drive_id)
        ->assertJsonPath('data.epoch', 0)
        ->assertJsonPath('data.schema_version', 1)
        ->assertJsonPath('data.room', 'vaulter.documents.'.$node->drive_id.'.'.$node->getKey().'.0');
});

it('issues a read-only session for a bare reader', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::READER);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session')
        ->assertCreated()
        ->assertJsonPath('data.mode', 'ro');
});

it('issues a writable session for a writer (edit)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::WRITER);

    $response = test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session')
        ->assertCreated()
        ->assertJsonPath('data.mode', 'rw');

    $claims = (array) JWT::decode($response->json('data.token'), new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));
    expect($claims)->toMatchArray([
        'sub' => '1',
        'node' => (string) $node->getKey(),
        'drive' => (string) $node->drive_id,
        'tenant' => null,
        'epoch' => 0,
        'schema_version' => 1,
        'mode' => 'rw',
        'room' => 'vaulter.documents.'.$node->drive_id.'.'.$node->getKey().'.0',
    ])->and($claims['exp'] - $claims['iat'])->toBe(3600);
});

it('issues a read-only session to an editor whose client speaks another schema version', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::WRITER);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session', ['schema_version' => 0])
        ->assertCreated()
        ->assertJsonPath('data.mode', 'ro')
        ->assertJsonPath('data.schema_version', 1);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session', ['schema_version' => 1])
        ->assertCreated()
        ->assertJsonPath('data.mode', 'rw');
});

it('returns a hocuspocus descriptor with url + JWT when that driver is configured', function (): void {
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
    config()->set('vaulter-documents.collaboration.hub.url', 'ws://hub:1234');

    actAsCollaborator();
    $node = grantedDocumentNode(level: RoleRegistry::WRITER);

    test()->postJson('api/vaulter/v1/documents/'.$node->getKey().'/collaboration/session')
        ->assertCreated()
        ->assertJsonPath('data.driver', 'hocuspocus')
        ->assertJsonPath('data.url', 'ws://hub:1234')
        ->assertJsonPath('data.mode', 'rw');
});

it('409s when the target node is a folder, not a document', function (): void {
    actAsCollaborator();
    $drive = DocumentTestHelpers::drive();
    $folder = AccessTestHelpers::folder($drive->model(), null, 'plain-folder');
    AccessTestHelpers::grant($folder, RoleRegistry::WRITER);

    // A folder has no document payload — collab is document-only.
    test()->postJson('api/vaulter/v1/documents/'.$folder->getKey().'/collaboration/session')
        ->assertStatus(409)->assertJsonPath('code', 'not_a_document');
});
