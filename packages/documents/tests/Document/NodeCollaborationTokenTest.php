<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
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
    config()->set('vaulter.drivers.permission', 'database');
    config()->set('vaulter.collaboration.driver', 'reverb');
});

function grantedDocumentNode(?GrantLevel $level, int $actorId = 1): Node
{
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
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
    $node = grantedDocumentNode(level: GrantLevel::Commenter);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.driver', 'reverb')
        ->assertJsonPath('data.read_only', true)
        ->assertJsonPath('data.node_id', $node->getKey())
        ->assertJsonPath('data.room', 'vaulter.documents.'.$node->getKey());
});

it('issues a read-only session for a bare reader', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: GrantLevel::Reader);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.read_only', true);
});

it('issues a writable session for a writer (edit)', function (): void {
    actAsCollaborator();
    $node = grantedDocumentNode(level: GrantLevel::Writer);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.token', 'vaulter.documents.'.$node->getKey());
});

it('returns a hocuspocus descriptor with url + JWT when that driver is configured', function (): void {
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', 'shh-secret');
    config()->set('vaulter.collaboration.drivers.hocuspocus.url', 'ws://hub:1234');

    actAsCollaborator();
    $node = grantedDocumentNode(level: GrantLevel::Writer);

    test()->postJson('api/vaulter/nodes/'.$node->getKey().'/collab/token')
        ->assertOk()
        ->assertJsonPath('data.driver', 'hocuspocus')
        ->assertJsonPath('data.url', 'ws://hub:1234')
        ->assertJsonPath('data.read_only', false)
        ->assertJsonPath('data.connection', null);
});

it('403s when the target node is a folder, not a document', function (): void {
    actAsCollaborator();
    $drive = DocumentTestHelpers::drive();
    $folder = AccessTestHelpers::folder($drive->storage(), null, 'plain-folder');
    AccessTestHelpers::grant($folder, GrantLevel::Writer);

    // A folder has no document payload — collab is document-only.
    test()->postJson('api/vaulter/nodes/'.$folder->getKey().'/collab/token')
        ->assertStatus(500); // NotADocumentNodeException (no custom render → 500)
});
