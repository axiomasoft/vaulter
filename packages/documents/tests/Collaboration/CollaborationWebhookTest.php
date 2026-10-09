<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Collaboration\CollaborationCallResolver;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Types\AcmeKanbanV2Type;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
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
const JWT_SECRET = 'jwt-signing-secret-that-is-at-least-32-bytes';

beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    config()->set('vaulter-documents.collaboration.driver', 'hocuspocus');
    config()->set('vaulter-documents.collaboration.hub.webhook_secret', WEBHOOK_SECRET);
    config()->set('vaulter-documents.collaboration.hub.jwt_secret', JWT_SECRET);
    config()->set('vaulter-documents.collaboration.hub.timestamp_tolerance', 300);

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

function webhookDocumentNode(
    ?string $level = RoleRegistry::WRITER,
    int $actorId = 1,
    ?DocumentContent $content = null,
    string $type = 'vaulter/rich-text',
    int $schemaVersion = 1,
): Node {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: $content ?? DocumentTestHelpers::content('Draft'),
        parent: DriveTestHelpers::model($drive->root()),
        type: $type,
        schemaVersion: $schemaVersion,
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
            'room' => app(DocumentsConfig::class)->collaborationRoom($node->getKey()),
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

    $state = Document::query()->where('node_id', $node->getKey())->firstOrFail();

    // Yjs binary is the source of truth — the exact bytes round-trip and the size is cached.
    expect($state->yjs_state)->toBe($yjs)
        ->and($state->yjs_state_size)->toBe(strlen($yjs))
        // The TipTap projection updated alongside.
        ->and($state->content['content'][0]['content'][0]['text'] ?? null)->toBe('From hub');
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

    $state = Document::query()->where('node_id', $node->getKey())->firstOrFail();

    expect($state->yjs_state)->toBe($yjs)
        ->and($state->yjs_state_size)->toBe(strlen($yjs));
});

it('rejects a store call with no secret and no JWT', function (): void {
    $node = webhookDocumentNode();

    test()->postJson('api/vaulter/collab/store', [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('bytes'),
    ])->assertStatus(401);

    expect(Document::query()->where('node_id', $node->getKey())->where('state_version', '>', 1)->exists())
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

    expect(Document::query()->where('node_id', $node->getKey())->firstOrFail()->yjs_state)
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

    expect(Document::query()->where('node_id', $nodeB->getKey())->where('state_version', '>', 1)->exists())
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
    $room = app(DocumentsConfig::class)->collaborationRoom($node->getKey());
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

    expect(Document::query()->where('node_id', $node->getKey())->firstOrFail()
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

    expect(Document::query()->where('node_id', $node->getKey())->where('state_version', '>', 1)->exists())
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

it('does not burn the nonce for a REJECTED tampered delivery, so the genuine original body still lands after (P3.2 review)', function (): void {
    $node = webhookDocumentNode();

    $signedBody = [
        'node_id' => $node->getKey(),
        'yjs_state' => base64_encode('original-bytes'),
    ];
    $headers = signedHeaders($signedBody, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]);

    // An attacker (or a duplicating proxy) races in a body-mutated copy under the
    // captured signature — correctly rejected (tamper-evidence).
    $tamperedBody = $signedBody;
    $tamperedBody['yjs_state'] = base64_encode('swapped-bytes');
    test()->postJson('api/vaulter/collab/store', $tamperedBody, $headers)->assertStatus(401);

    // The genuine delivery of the ORIGINAL, untampered body under the same
    // signature must still be accepted — the nonce must only be consumed once the
    // signature is confirmed valid for the exact rawBody presented, never before.
    test()->postJson('api/vaulter/collab/store', $signedBody, $headers)->assertOk();
});

// ── collab/authenticate ─────────────────────────────────────────────────────────────

it('authenticates a good room JWT and answers allowed + readOnly from the ability', function (): void {
    $node = webhookDocumentNode(level: RoleRegistry::WRITER, actorId: 1);

    $body = ['node_id' => $node->getKey()];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false)
        ->assertJsonPath('user.id', '1');
});

it('authenticates when the hub sends node_id EQUAL to the room name (regression)', function (): void {
    $node = webhookDocumentNode(level: RoleRegistry::WRITER, actorId: 1);
    $room = app(DocumentsConfig::class)->collaborationRoom($node->getKey());

    // Тот же перекос node_id==room, что в collab/store — но на пути authenticate:
    // нерезолвленный узел дал бы allowed:false и хаб отказал бы writer'у в коннекте.
    $body = ['room' => $room, 'node_id' => $room];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false);
});

it('answers readOnly=true when the user is only a commenter', function (): void {
    $node = webhookDocumentNode(level: RoleRegistry::COMMENTER, actorId: 1);

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
    $node = webhookDocumentNode(level: RoleRegistry::WRITER, actorId: 5);

    $body = ['node_id' => $node->getKey(), 'user_id' => 5];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', false);
});

it('rejects an authenticate call with valid JWT identity but no signature (F-P1.6-02)', function (): void {
    $node = webhookDocumentNode(level: RoleRegistry::WRITER, actorId: 1);

    test()->postJson('api/vaulter/collab/authenticate', [
        'node_id' => $node->getKey(),
    ], ['Authorization' => 'Bearer '.roomJwt($node, readOnly: false, actorId: 1)])
        ->assertStatus(401);
});

// ── content this installation cannot save back (PLAN2.P4.3, Grok 4.7 review) ─────
//
// The hub takes readOnly from collab/authenticate and stores whatever the room holds,
// so both hooks answer from the same rule as DocumentOperations::update(): a type that
// is not installed, content written in a newer schema, or content the type reads only
// partly is shown, never overwritten — not by the Yjs state, not by a projection.

/** @return array<string, mixed> */
function collabWidgetDocument(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
        ['type' => 'acmeWidget'],
    ]];
}

function degradedWebhookNode(): Node
{
    config()->set('vaulter-documents.tiptap.extra_nodes', ['acmeWidget']);
    $node = webhookDocumentNode(content: TipTapContent::fromArray(collabWidgetDocument()));
    // The host switches the extension off: the widget no longer reads.
    config()->set('vaulter-documents.tiptap.extra_nodes', []);

    return $node;
}

dataset('unwritable collaboration documents', [
    'degraded read' => [fn (): Node => degradedWebhookNode(), 'document_content_degraded'],
    'newer schema' => [fn (): Node => webhookDocumentNode(schemaVersion: 2), 'document_content_degraded'],
    'type not installed' => [fn (): Node => webhookDocumentNode(content: OpaqueContent::fromArray(['cells' => []]), type: 'acme/gone'), 'document_type_unavailable'],
]);

it('authenticates an editor read-only on content that cannot be saved back', function (Closure $node): void {
    $node = $node();
    $body = ['node_id' => $node->getKey(), 'user_id' => 1];

    test()->postJson('api/vaulter/collab/authenticate', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('readOnly', true);
})->with('unwritable collaboration documents');

it('refuses a store over content that cannot be saved back, with or without a projection', function (Closure $node, string $code): void {
    $node = $node();
    $before = Document::query()->where('node_id', $node->getKey())->firstOrFail();

    $yjsOnly = ['node_id' => $node->getKey(), 'yjs_state' => base64_encode('room-without-widget')];
    $withProjection = [...$yjsOnly, 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'From hub']]],
    ]]];

    foreach ([$yjsOnly, $withProjection] as $body) {
        test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
            ->assertStatus(409)
            ->assertJsonPath('code', $code);
    }

    $after = Document::query()->where('node_id', $node->getKey())->firstOrFail();
    expect($after->content)->toBe($before->content)
        ->and($after->yjs_state)->toBeNull()
        ->and($after->schema_version)->toBe($before->schema_version)
        ->and($after->state_version)->toBe($before->state_version);
})->with('unwritable collaboration documents');

it('keeps the stored schema version on a Yjs-only store and writes the type\'s with a projection', function (int $stored): void {
    TypesTestCase::installTypes([RichTextDocumentType::class, MarkdownDocumentType::class, AcmeKanbanV2Type::class]);
    $node = webhookDocumentNode(content: OpaqueContent::fromArray(['columns' => []]), type: 'acme/kanban', schemaVersion: $stored);
    $schema = fn (): int => Document::query()->where('node_id', $node->getKey())->firstOrFail()->schema_version;

    // The JSON is untouched, so it stays in the schema it was written in.
    $yjsOnly = ['node_id' => $node->getKey(), 'yjs_state' => base64_encode('board-room')];
    test()->postJson('api/vaulter/collab/store', $yjsOnly, signedHeaders($yjsOnly, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertOk();
    expect($schema())->toBe($stored);

    // A projection is decoded by the installed type and written in its schema.
    $projected = [...$yjsOnly, 'yjs_state' => base64_encode('board-room-2'), 'content' => ['columns' => [['title' => 'Todo', 'items' => []]]]];
    test()->postJson('api/vaulter/collab/store', $projected, signedHeaders($projected, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertOk();
    expect($schema())->toBe(2)
        ->and(Document::query()->where('node_id', $node->getKey())->firstOrFail()->content)
        ->toBe(['columns' => [['title' => 'Todo', 'items' => []]]]);
})->with(['older schema' => 1, 'current schema' => 2]);

it('decodes a store projection with the document type\'s own schema', function (): void {
    $empty = ['type' => 'doc', 'content' => []];
    $node = webhookDocumentNode(content: TipTapContent::fromArray($empty, TipTapSchema::markdown()), type: 'vaulter/markdown');

    // A table is rich text, not markdown: storing it would leave the document degraded.
    $body = ['node_id' => $node->getKey(), 'yjs_state' => base64_encode('markdown-room'), 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell']]]]],
    ]]];

    test()->postJson('api/vaulter/collab/store', $body, signedHeaders($body, ['X-Vaulter-Collab-Secret' => WEBHOOK_SECRET]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_document_content');

    $state = Document::query()->where('node_id', $node->getKey())->firstOrFail();
    expect($state->content)->toBe($empty)
        ->and($state->yjs_state)->toBeNull();
});
