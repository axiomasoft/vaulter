<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Collaboration\CollaborationDriverManager;
use Vaulter\Documents\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * Драйверный слой M4b (мастер-план §7 / ADR-0001): issueSession кодирует readOnly из
 * способности (Edit → writable, иначе read-only), Hocuspocus подписывает JWT с
 * правильными claim'ами/exp, а комната всегда привязана к одному узлу. Это «сердце»
 * выдачи токена — проверяем форму дескриптора для Hocuspocus отдельно от HTTP.
 */
function documentNode(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('First draft'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

it('mints a hocuspocus room JWT with the room/sub/readOnly/exp claims', function (): void {
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
    config()->set('vaulter-documents.collaboration.hub.url', 'ws://hub:1234');
    config()->set('vaulter-documents.collaboration.hub.token_ttl', 900);

    $node = documentNode();
    $before = time();
    $session = app(HocuspocusCollaborationDriver::class)->issueSession($node, Ability::Edit, actorId: 42);

    expect($session->driver)->toBe(CollaborationDriverType::Hocuspocus)
        ->and($session->url)->toBe('ws://hub:1234')
        ->and($session->connection)->toBeNull()
        ->and($session->readOnly)->toBeFalse()
        ->and($session->token)->toBeString()->not->toBe('');

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($claims->room)->toBe(app(DocumentsConfig::class)->collaborationRoom($node->getKey()))
        ->and($claims->sub)->toBe('42')
        ->and($claims->readOnly)->toBeFalse()
        ->and($claims->exp)->toBeGreaterThanOrEqual($before + 900)
        ->and($claims->exp)->toBeLessThanOrEqual(time() + 900);
});

it('encodes readOnly=true into the hocuspocus JWT for a comment ability', function (): void {
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $node = documentNode();
    $session = app(HocuspocusCollaborationDriver::class)->issueSession($node, Ability::Comment, actorId: 1);

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($session->readOnly)->toBeTrue()
        ->and($claims->readOnly)->toBeTrue();
});

it('binds the hocuspocus room JWT to a single node', function (): void {
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $nodeA = documentNode();
    $nodeB = documentNode();

    $sessionA = app(HocuspocusCollaborationDriver::class)->issueSession($nodeA, Ability::Edit, actorId: 1);
    $claimsA = JWT::decode($sessionA->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    // The token for node A carries node A's room — never node B's.
    expect($claimsA->room)->toBe(app(DocumentsConfig::class)->collaborationRoom($nodeA->getKey()))
        ->and($claimsA->room)->not->toBe(app(DocumentsConfig::class)->collaborationRoom($nodeB->getKey()));
});

it('resolves the node-collaboration driver from config', function (): void {
    $manager = app(CollaborationDriverManager::class);

    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    expect($manager->driver())->toBeInstanceOf(HocuspocusCollaborationDriver::class);
});

it('rejects an unknown node-collaboration driver with an operator-facing remedy', function (): void {
    $manager = app(CollaborationDriverManager::class);

    expect(fn () => $manager->driver('unknown'))
        ->toThrow(
            VaulterException::class,
            '[Vaulter] Unknown collaboration driver [unknown]. Fix: set config(vaulter-documents.collaboration.driver) to hocuspocus.',
        );
});
