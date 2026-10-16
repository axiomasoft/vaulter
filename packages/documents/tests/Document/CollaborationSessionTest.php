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
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Drive\Node;

/**
 * Драйверный слой M4b (мастер-план §7 / ADR-0001): issueSession кодирует режим (rw/ro) из
 * writable, Hocuspocus подписывает JWT с правильными claim'ами/exp, а комната всегда
 * привязана к одному узлу и поколению. Это «сердце» выдачи токена — проверяем форму
 * дескриптора для Hocuspocus отдельно от HTTP.
 */
function roomOf(Node $node, int $epoch = 0): string
{
    return app(DocumentsConfig::class)->collaborationRoom((string) $node->drive_id, (string) $node->getKey(), $epoch);
}

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

it('mints a hocuspocus room JWT with the room/sub/mode/epoch/exp claims', function (): void {
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
    config()->set('vaulter-documents.collaboration.hub.url', 'ws://hub:1234');
    config()->set('vaulter-documents.collaboration.hub.token_ttl', 900);

    $node = documentNode();
    $before = time();
    $session = app(HocuspocusCollaborationDriver::class)->issueSession($node, epoch: 3, schemaVersion: 1, writable: true, actorId: 42);

    expect($session->driver)->toBe(CollaborationDriverType::Hocuspocus)
        ->and($session->url)->toBe('ws://hub:1234')
        ->and($session->readOnly)->toBeFalse()
        ->and($session->token)->toBeString()->not->toBe('');

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($claims->room)->toBe(roomOf($node, 3))
        ->and($claims->sub)->toBe('42')
        ->and($claims->node)->toBe((string) $node->getKey())
        ->and($claims->epoch)->toBe(3)
        ->and($claims->mode)->toBe('rw')
        ->and($claims->exp)->toBeGreaterThanOrEqual($before + 900)
        ->and($claims->exp)->toBeLessThanOrEqual(time() + 900);
});

it('encodes the ro mode into the hocuspocus JWT of a read-only session', function (): void {
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $node = documentNode();
    $session = app(HocuspocusCollaborationDriver::class)->issueSession($node, epoch: 0, schemaVersion: 1, writable: false, actorId: 1);

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($session->readOnly)->toBeTrue()
        ->and($claims->mode)->toBe('ro');
});

it('binds the hocuspocus room JWT to a single node', function (): void {
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $nodeA = documentNode();
    $nodeB = documentNode();

    $sessionA = app(HocuspocusCollaborationDriver::class)->issueSession($nodeA, epoch: 0, schemaVersion: 1, writable: true, actorId: 1);
    $claimsA = JWT::decode($sessionA->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    // The token for node A carries node A's room — never node B's.
    expect($claimsA->room)->toBe(roomOf($nodeA))
        ->and($claimsA->room)->not->toBe(roomOf($nodeB));
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
