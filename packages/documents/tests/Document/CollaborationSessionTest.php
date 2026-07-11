<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Collaboration\HocuspocusNodeCollaborationDriver;
use Vaulter\Documents\Collaboration\NodeCollaborationDriverManager;
use Vaulter\Documents\Collaboration\ReverbNodeCollaborationDriver;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Enums\CollaborationDriverType;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * Драйверный слой M4b (мастер-план §7 / ADR-0001): issueSession кодирует readOnly из
 * способности (Edit → writable, иначе read-only), Hocuspocus подписывает JWT с
 * правильными claim'ами/exp, а комната всегда привязана к одному узлу. Это «сердце»
 * выдачи токена — проверяем форму дескриптора для обоих транспортов отдельно от HTTP.
 */
function documentNode(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Spec',
        content: DocumentTestHelpers::content('First draft'),
        parent: $drive->root(),
    ));
}

it('issues a reverb session whose token is the private room channel', function (): void {
    config()->set('vaulter.collaboration.driver', 'reverb');

    $node = documentNode();
    $session = app(ReverbNodeCollaborationDriver::class)->issueSession($node, Ability::Edit, actorId: 7);

    expect($session->driver)->toBe(CollaborationDriverType::Reverb)
        ->and($session->room)->toBe(Config::collaborationRoom($node->getKey()))
        ->and($session->room)->toBe('vaulter.documents.'.$node->getKey())
        ->and($session->token)->toBe($session->room)
        ->and($session->readOnly)->toBeFalse()
        ->and($session->url)->toBeNull()
        ->and($session->connection)->toBe('reverb');
});

it('marks a reverb session read-only for a non-edit ability', function (): void {
    $node = documentNode();

    $view = app(ReverbNodeCollaborationDriver::class)->issueSession($node, Ability::View, actorId: 7);
    $comment = app(ReverbNodeCollaborationDriver::class)->issueSession($node, Ability::Comment, actorId: 7);

    expect($view->readOnly)->toBeTrue()
        ->and($comment->readOnly)->toBeTrue();
});

it('mints a hocuspocus room JWT with the room/sub/readOnly/exp claims', function (): void {
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', 'shh-secret-at-least-32-bytes-long-for-hs256');
    config()->set('vaulter.collaboration.drivers.hocuspocus.url', 'ws://hub:1234');
    config()->set('vaulter.collaboration.drivers.hocuspocus.token_ttl', 900);

    $node = documentNode();
    $before = time();
    $session = app(HocuspocusNodeCollaborationDriver::class)->issueSession($node, Ability::Edit, actorId: 42);

    expect($session->driver)->toBe(CollaborationDriverType::Hocuspocus)
        ->and($session->url)->toBe('ws://hub:1234')
        ->and($session->connection)->toBeNull()
        ->and($session->readOnly)->toBeFalse()
        ->and($session->token)->toBeString()->not->toBe('');

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($claims->room)->toBe(Config::collaborationRoom($node->getKey()))
        ->and($claims->sub)->toBe('42')
        ->and($claims->readOnly)->toBeFalse()
        ->and($claims->exp)->toBeGreaterThanOrEqual($before + 900)
        ->and($claims->exp)->toBeLessThanOrEqual(time() + 900);
});

it('encodes readOnly=true into the hocuspocus JWT for a comment ability', function (): void {
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $node = documentNode();
    $session = app(HocuspocusNodeCollaborationDriver::class)->issueSession($node, Ability::Comment, actorId: 1);

    $claims = JWT::decode($session->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    expect($session->readOnly)->toBeTrue()
        ->and($claims->readOnly)->toBeTrue();
});

it('binds the hocuspocus room JWT to a single node', function (): void {
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', 'shh-secret-at-least-32-bytes-long-for-hs256');

    $nodeA = documentNode();
    $nodeB = documentNode();

    $sessionA = app(HocuspocusNodeCollaborationDriver::class)->issueSession($nodeA, Ability::Edit, actorId: 1);
    $claimsA = JWT::decode($sessionA->token, new Key('shh-secret-at-least-32-bytes-long-for-hs256', 'HS256'));

    // The token for node A carries node A's room — never node B's.
    expect($claimsA->room)->toBe(Config::collaborationRoom($nodeA->getKey()))
        ->and($claimsA->room)->not->toBe(Config::collaborationRoom($nodeB->getKey()));
});

it('resolves the node-collaboration driver from config', function (): void {
    $manager = app(NodeCollaborationDriverManager::class);

    config()->set('vaulter.collaboration.driver', 'reverb');
    expect($manager->driver())->toBeInstanceOf(ReverbNodeCollaborationDriver::class);

    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    expect($manager->driver())->toBeInstanceOf(HocuspocusNodeCollaborationDriver::class);
});
