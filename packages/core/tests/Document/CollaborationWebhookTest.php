<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Vaulter\Actions\Document\CreateDocumentNodeAction;
use Vaulter\Contracts\HostUserResolver;
use Vaulter\Data\CreateDocumentNodeData;
use Vaulter\Enums\GrantLevel;
use Vaulter\Models\Node;
use Vaulter\Models\NodeDocumentVersion;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Document\DocumentTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;

/**
 * Серверные webhook'и M4b (мастер-план §7 / ADR-0001) — то, что зовёт Node-хаб
 * Hocuspocus: onAuthenticate → collab/authenticate, onStoreDocument → collab/store.
 * Эндпоинты НИКОГДА не открыты: пускают только по shared-секрету ИЛИ валидному
 * подписанному JWT. Yjs-бинарь — источник истины (round-trip bytea + размер), TipTap —
 * проекция. Комната жёстко привязана к узлу: токен узла A не может писать в узел B.
 */
const WEBHOOK_SECRET = 'webhook-shared-secret';
const JWT_SECRET = 'jwt-signing-secret';

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.webhook_secret', WEBHOOK_SECRET);
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', JWT_SECRET);

    // The webhook authenticate flow resolves the acting user by id — bind a fake
    // resolver so it works without a configured host user provider.
    app()->bind(HostUserResolver::class, fn (): FakeHostUserResolver => new FakeHostUserResolver);
});

function webhookDocumentNode(?GrantLevel $level = GrantLevel::Writer, int $actorId = 1): Node
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

function roomJwt(Node $node, bool $readOnly, int $actorId = 1, ?int $exp = null): string
{
    return JWT::encode(
        payload: [
            'room' => Config::collaborationRoom($node->getKey()),
            'sub' => (string) $actorId,
            'readOnly' => $readOnly,
            'iat' => time(),
            'exp' => $exp ?? (time() + 3600),
        ],
        key: JWT_SECRET,
        alg: 'HS256',
    );
}

// ── collab/store ──────────────────────────────────────────────────────────────────

it('persists the yjs snapshot with the shared secret (bytea round-trips, size set)', function (): void {
    $node = webhookDocumentNode();
    $yjs = "\x00\x01\x02\x03binary-yjs-state\xff\xfe";

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From hub']]],
        ]],
    ], ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET])
        ->assertOk()
        ->assertJsonPath('data.node_id', $node->getKey());

    $version = NodeDocumentVersion::query()
        ->where('node_id', $node->getKey())
        ->orderByDesc('version_no')
        ->firstOrFail();

    // Yjs binary is the source of truth — the exact bytes round-trip and the size is cached.
    expect($version->yjs_state)->toBe($yjs)
        ->and($version->yjs_state_size)->toBe(strlen($yjs))
        // The TipTap projection updated alongside.
        ->and($version->content['content'][0]['content'][0]['text'] ?? null)->toBe('From hub');
});

it('persists a yjs snapshot WITHOUT a projection, leaving Yjs the source of truth', function (): void {
    $node = webhookDocumentNode();
    $yjs = 'state-without-projection';

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
    ], ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET])
        ->assertOk();

    $version = NodeDocumentVersion::query()
        ->where('node_id', $node->getKey())
        ->orderByDesc('version_no')
        ->firstOrFail();

    expect($version->yjs_state)->toBe($yjs)
        ->and($version->yjs_state_size)->toBe(strlen($yjs));
});

it('rejects a store call with no secret and no JWT', function (): void {
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ])->assertStatus(401);

    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->where('version_no', '>', 1)->exists())
        ->toBeFalse();
});

it('rejects a store call with the wrong secret', function (): void {
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ], ['X-Vaulter-Collab-Secret' => 'wrong-secret'])
        ->assertStatus(401);
});

it('accepts a store call authenticated by a writable room JWT', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null); // force the JWT path
    $node = webhookDocumentNode();
    $yjs = 'jwt-authenticated-state';

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false)])
        ->assertOk();

    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->orderByDesc('version_no')->firstOrFail()->yjs_state)
        ->toBe($yjs);
});

it('rejects a store call driven by a READ-ONLY room JWT', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: true)])
        ->assertForbidden();
});

it('forbids a JWT minted for node A from storing to node B (room bound to node)', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $nodeA = webhookDocumentNode();
    $nodeB = webhookDocumentNode();

    // A writable token for node A, but the body addresses node B.
    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $nodeB->getKey(),
        'yjs_state' => base64_encode('cross-node'),
    ], ['Authorization' => 'Bearer '.roomJwt($nodeA, readOnly: false)])
        ->assertForbidden();

    expect(NodeDocumentVersion::query()->where('node_id', $nodeB->getKey())->where('version_no', '>', 1)->exists())
        ->toBeFalse();
});

it('rejects a store call with a tampered JWT', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode();
    $tampered = roomJwt($node, readOnly: false).'x';

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ], ['Authorization' => 'Bearer '.$tampered])
        ->assertStatus(401);
});

// ── collab/authenticate ─────────────────────────────────────────────────────────────

it('authenticates a good room JWT and answers allowed + readOnly from the ability', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 1);

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)])
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false)
        ->assertJsonPath('user.id', '1');
});

it('answers readOnly=true when the user is only a commenter', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode(level: GrantLevel::Commenter, actorId: 1);

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: true, actorId: 1)])
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', true);
});

it('answers allowed=false when the user has no access to the node', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode(level: null, actorId: 1); // no grant

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)])
        ->assertOk()
        ->assertJsonPath('allowed', false);
});

it('rejects an expired JWT on authenticate', function (): void {
    config()->set('vaulter.collaboration.webhook_secret', null);
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, exp: time() - 60)])
        ->assertStatus(401);
});

it('authenticates a shared-secret call carrying the user out of band', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 5);

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
        'user_id' => 5,
    ], ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET])
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false);
});
