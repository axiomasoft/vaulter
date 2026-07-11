<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Collaboration\CollaborationCallResolver;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Серверные webhook'и M4b (мастер-план §7 / ADR-0001) — то, что зовёт Node-хаб
 * Hocuspocus: onAuthenticate → collab/authenticate, onStoreDocument → collab/store.
 * Эндпоинты НИКОГДА не открыты: пускают только по shared-секрету ИЛИ валидному
 * подписанному JWT — И (VLT-REMED P3.2 / F-P1.6-02) валидной HMAC-подписи ТЕЛА поверх
 * личности звонящего (`signedHeaders()` — единственный способ пройти оба барьера в
 * этом файле). Yjs-бинарь — источник истины (round-trip bytea + размер), TipTap —
 * проекция. Комната жёстко привязана к узлу: токен узла A не может писать в узел B.
 */
const WEBHOOK_SECRET = 'webhook-shared-secret';
const JWT_SECRET = 'jwt-signing-secret';

beforeEach(function (): void {
    config()->set('vaulter.drivers.permission', 'database');
    config()->set('vaulter.collaboration.driver', 'hocuspocus');
    config()->set('vaulter.collaboration.webhook_secret', WEBHOOK_SECRET);
    config()->set('vaulter.collaboration.drivers.hocuspocus.secret', JWT_SECRET);
    config()->set('vaulter.collaboration.webhook_timestamp_tolerance', 300);

    // The webhook authenticate flow resolves the acting user by id — bind a fake
    // resolver so it works without a configured host user provider.
    app()->bind(HostUserResolver::class, fn (): FakeHostUserResolver => new FakeHostUserResolver);
});

/**
 * Signs `$body` with the SAME canonical string ("{timestamp}.{rawBody}") the
 * production resolver re-derives ({@see CollaborationCallResolver}), merging in any
 * identity headers (secret/bearer). This is the ONLY way a request in this file
 * reaches the controller — an unsigned request 401s at the middleware regardless of
 * identity (F-P1.6-02).
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string> $identityHeaders
 * @return array<string, string>
 */
function signedHeaders(array $body, array $identityHeaders = [], ?int $timestamp = null, ?string $secret = null): array
{
    $timestamp ??= time();
    $rawBody = json_encode($body);
    $signature = (new WebhookSigner)->sign(body: $rawBody, secret: $secret ?? WEBHOOK_SECRET, timestamp: $timestamp);

    return array_merge($identityHeaders, [
        CollaborationCallResolver::TIMESTAMP_HEADER => (string) $timestamp,
        CollaborationCallResolver::SIGNATURE_HEADER => $signature,
    ]);
}

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

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From hub']]],
        ]],
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
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

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
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

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => 'wrong-secret']))
        ->assertStatus(401);
});

it('accepts a store call authenticated by a writable room JWT', function (): void {
    $node = webhookDocumentNode();
    $yjs = 'jwt-authenticated-state';

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode($yjs),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false)]))
        ->assertOk();

    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->orderByDesc('version_no')->firstOrFail()->yjs_state)
        ->toBe($yjs);
});

it('rejects a store call driven by a READ-ONLY room JWT', function (): void {
    $node = webhookDocumentNode();

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: true)]))
        ->assertForbidden();
});

it('forbids a JWT minted for node A from storing to node B (room bound to node)', function (): void {
    $nodeA = webhookDocumentNode();
    $nodeB = webhookDocumentNode();

    // A writable token for node A, but the body addresses node B.
    $body = [
        'node_id' => $nodeB->getKey(),
        'yjs_state' => base64_encode('cross-node'),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($nodeA, readOnly: false)]))
        ->assertForbidden();

    expect(NodeDocumentVersion::query()->where('node_id', $nodeB->getKey())->where('version_no', '>', 1)->exists())
        ->toBeFalse();
});

it('rejects a store call with a tampered JWT', function (): void {
    $node = webhookDocumentNode();
    $tampered = roomJwt($node, readOnly: false).'x';

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['Authorization' => 'Bearer '.$tampered]))
        ->assertStatus(401);
});

it('resolves the node when the hub sends node_id EQUAL to the room name (regression)', function (): void {
    $node = webhookDocumentNode();
    $room = Config::collaborationRoom($node->getKey());
    $yjs = 'hub-room-shaped-node-id';

    // Регрессия: Node-хаб (hocuspocus-server/src/webhook.js) шлёт node_id РАВНЫМ имени
    // комнаты (vaulter.documents.<id>), а не голым ключом — контроллер обязан его
    // нормализовать, иначе find('vaulter.documents.N') даёт 404 и снапшот теряется.
    $body = [
        'room' => $room,
        'node_id' => $room,
        'yjs_state' => base64_encode($yjs),
    ];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false)]))
        ->assertOk()
        ->assertJsonPath('data.node_id', $node->getKey());

    expect(NodeDocumentVersion::query()
        ->where('node_id', $node->getKey())
        ->orderByDesc('version_no')
        ->firstOrFail()
        ->yjs_state)->toBe($yjs);
});

// ── HMAC body signature (F-P1.6-02) ─────────────────────────────────────────────────

it('rejects a store call with valid secret identity but NO signature at all', function (): void {
    // Red-before: pre-P3.2, identity alone (X-Vaulter-Collab-Secret) was sufficient —
    // the body was never signed. Now identity passing is NOT enough; the missing
    // signature must still 401 (not fall through to a 204/200).
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ], ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET])
        ->assertStatus(401);

    expect(NodeDocumentVersion::query()->where('node_id', $node->getKey())->where('version_no', '>', 1)->exists())
        ->toBeFalse();
});

it('rejects a store call with a signature whose timestamp is outside the ±300s window', function (): void {
    $node = webhookDocumentNode();

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ];

    $stale = signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET], timestamp: time() - 301);

    test()->postJson('api/vaulter/collab/store', $body, $stale)->assertStatus(401);
});

it('rejects a replayed signature (same nonce twice)', function (): void {
    $node = webhookDocumentNode();

    $body = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('replay-bytes'),
    ];

    $headers = signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]);

    // First delivery: valid, fresh signature → accepted.
    test()->postJson('api/vaulter/collab/store', $body, $headers)->assertOk();

    // Second delivery: byte-identical request (same timestamp + signature) → replay, refused.
    test()->postJson('api/vaulter/collab/store', $body, $headers)->assertStatus(401);
});

it('rejects a store call whose signature was computed over a DIFFERENT body (tamper-evidence)', function (): void {
    $node = webhookDocumentNode();

    $signedBody = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('original-bytes'),
    ];
    $headers = signedHeaders($signedBody, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]);

    // The wire body differs from what was signed — the HMAC no longer matches.
    $tamperedBody = $signedBody;
    $tamperedBody['yjs_state'] = base64_encode('swapped-bytes');

    test()->postJson('api/vaulter/collab/store', $tamperedBody, $headers)->assertStatus(401);
});

// ── collab/authenticate ─────────────────────────────────────────────────────────────

it('authenticates a good room JWT and answers allowed + readOnly from the ability', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 1);

    $body = ['node_id' => $node->getKey()];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false)
        ->assertJsonPath('user.id', '1');
});

it('authenticates when the hub sends node_id EQUAL to the room name (regression)', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 1);
    $room = Config::collaborationRoom($node->getKey());

    // Тот же перекос node_id==room, что в collab/store — но на пути authenticate:
    // нерезолвленный узел дал бы allowed:false и хаб отказал бы writer'у в коннекте.
    $body = ['room' => $room, 'node_id' => $room];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false);
});

it('answers readOnly=true when the user is only a commenter', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Commenter, actorId: 1);

    $body = ['node_id' => $node->getKey()];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: true, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', true);
});

it('answers allowed=false when the user has no access to the node', function (): void {
    $node = webhookDocumentNode(level: null, actorId: 1); // no grant

    $body = ['node_id' => $node->getKey()];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', false);
});

it('rejects an expired JWT on authenticate', function (): void {
    $node = webhookDocumentNode();

    $body = ['node_id' => $node->getKey()];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, exp: time() - 60)]))
        ->assertStatus(401);
});

it('authenticates a shared-secret call carrying the user out of band', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 5);

    $body = ['node_id' => $node->getKey(), 'user_id' => 5];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false);
});

it('rejects an authenticate call with valid JWT identity but no signature (F-P1.6-02)', function (): void {
    $node = webhookDocumentNode(level: GrantLevel::Writer, actorId: 1);

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)])
        ->assertStatus(401);
});
