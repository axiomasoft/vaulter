<?php

declare(strict_types=1);

use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\AcmeKanbanV2Type;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Node;

/**
 * Серверные вызовы хаба Hocuspocus: authenticate / load / store. Пускают только с
 * room-JWT И валидной HMAC-подписью тела (оба барьера обязательны; shared-секрет
 * личностью больше не является). Yjs-бинарь — источник истины (round-trip bytea +
 * размер), TipTap — проекция. Комната жёстко привязана к узлу и поколению.
 */
beforeEach(function (): void {
    Hub::configure();
});

// ── collab/store ──────────────────────────────────────────────────────────────────

it('persists the yjs state with its projection (bytea round-trips, size set)', function (): void {
    $node = Hub::document();
    $yjs = "\x00\x01\x02\x03binary-yjs-state\xff\xfe";

    Hub::call('store', Hub::storeBody($node, $yjs), Hub::token($node))
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.epoch', 0);

    $state = Hub::aggregate($node);

    expect($state->yjs_state)->toBe($yjs)
        ->and($state->yjs_state_size)->toBe(strlen($yjs))
        ->and($state->content['content'][0]['content'][0]['text'] ?? null)->toBe('From hub');
});

it('requires the JSON projection for a type that cannot derive it from the state', function (): void {
    $node = Hub::document();
    $body = ['room' => Hub::room($node), 'state' => base64_encode('state-without-projection')];

    Hub::call('store', $body, Hub::token($node))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_document_content');

    expect(Hub::aggregate($node)->yjs_state)->toBeNull();
});

it('rejects a store call without a token, even when signed', function (): void {
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'bytes'), null)->assertStatus(401);

    expect(Hub::aggregate($node)->state_version)->toBe(1);
});

it('no longer accepts the shared webhook secret as an identity', function (): void {
    $node = Hub::document();
    $body = Hub::storeBody($node, 'bytes');

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, [
        ...Hub::headers($body, null),
        'X-Vaulter-Collab-Secret' => Hub::WEBHOOK_SECRET,
    ])->assertStatus(401);
});

it('rejects a store call driven by a read-only room token', function (): void {
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'bytes'), Hub::token($node, mode: 'ro'))
        ->assertForbidden();

    expect(Hub::aggregate($node)->yjs_state)->toBeNull();
});

it('forbids a token minted for node A from addressing node B', function (): void {
    $nodeA = Hub::document();
    $nodeB = Hub::document();

    Hub::call('store', Hub::storeBody($nodeB, 'cross-node'), Hub::token($nodeA))
        ->assertForbidden();

    expect(Hub::aggregate($nodeB)->state_version)->toBe(1);
});

it('rejects a tampered token and a token whose claims disagree with its room', function (array $claims, string $suffix): void {
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'bytes'), Hub::token($node, claims: $claims).$suffix)
        ->assertStatus(401);
})->with([
    'tampered signature' => [[], 'x'],
    'node claim of another node' => [['node' => '999999'], ''],
    'epoch claim off the room' => [['epoch' => 7], ''],
    'unknown mode' => [['mode' => 'admin'], ''],
    'pre-0.3 room without epoch' => [['room' => 'vaulter.documents.1'], ''],
    'expired' => [['exp' => time() - 60], ''],
]);

// ── HMAC body signature ─────────────────────────────────────────────────────────────

it('rejects a token without a body signature', function (): void {
    $node = Hub::document();

    test()->postJson('api/vaulter/v1/collaboration/hub/store', Hub::storeBody($node, 'bytes'), ['Authorization' => 'Bearer '.Hub::token($node)])
        ->assertStatus(401);

    expect(Hub::aggregate($node)->state_version)->toBe(1);
});

it('rejects a signature whose timestamp is outside the ±300s window', function (): void {
    $node = Hub::document();
    $body = Hub::storeBody($node, 'bytes');

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, Hub::headers($body, Hub::token($node), timestamp: time() - 301))
        ->assertStatus(401);
});

it('rejects a replayed signature (same nonce twice)', function (): void {
    $node = Hub::document();
    $body = Hub::storeBody($node, 'replay-bytes');
    $headers = Hub::headers($body, Hub::token($node));

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, $headers)->assertOk();
    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, $headers)->assertStatus(401);
});

it('keeps the nonce until the signature itself expires, not just tolerance seconds from receipt', function (): void {
    $node = Hub::document();
    $body = Hub::storeBody($node, 'late-replay-bytes');
    // Future-dated within tolerance: the signature stays valid for ~550s from now.
    $headers = Hub::headers($body, Hub::token($node), timestamp: time() + 250);

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, $headers)->assertOk();

    $this->travel(400)->seconds();

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $body, $headers)->assertStatus(401);
});

it('rejects a body other than the signed one, without burning the nonce of the genuine delivery', function (): void {
    $node = Hub::document();
    $signed = Hub::storeBody($node, 'original-bytes');
    $headers = Hub::headers($signed, Hub::token($node));
    $tampered = [...$signed, 'state' => base64_encode('swapped-bytes')];

    test()->postJson('api/vaulter/v1/collaboration/hub/store', $tampered, $headers)->assertStatus(401);
    test()->postJson('api/vaulter/v1/collaboration/hub/store', $signed, $headers)->assertOk();

    expect(Hub::aggregate($node)->yjs_state)->toBe('original-bytes');
});

// ── collab/authenticate ─────────────────────────────────────────────────────────────

it('authenticates a writer read-write and a commenter read-only', function (string $level, string $mode): void {
    $node = Hub::document(level: $level);

    Hub::call('authenticate', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('mode', $mode)
        ->assertJsonPath('userId', '1')
        ->assertJsonPath('epoch', 0);
})->with([
    'writer' => [RoleRegistry::WRITER, 'rw'],
    'commenter' => [RoleRegistry::COMMENTER, 'ro'],
]);

it('answers allowed=false when the user has no access to the node', function (): void {
    $node = Hub::document(level: null);

    Hub::call('authenticate', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('allowed', false);
});

// ── content this installation cannot save back ──────────────────────────────────────
//
// Both hooks answer from the same rule as DocumentOperations::update(): a type that is
// not installed, content written in a newer schema, or content the type reads only
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
    $node = Hub::document(content: TipTapContent::fromArray(collabWidgetDocument()));
    // The host switches the extension off: the widget no longer reads.
    config()->set('vaulter-documents.tiptap.extra_nodes', []);

    return $node;
}

dataset('unwritable collaboration documents', [
    'degraded read' => [fn (): Node => degradedWebhookNode(), 'document_content_degraded'],
    'newer schema' => [fn (): Node => Hub::document(schemaVersion: 2), 'document_content_degraded'],
    'type not installed' => [fn (): Node => Hub::document(content: OpaqueContent::fromArray(['cells' => []]), type: 'acme/gone'), 'document_type_unavailable'],
]);

it('authenticates an editor read-only on content that cannot be saved back', function (Closure $node): void {
    $node = $node();

    Hub::call('authenticate', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('mode', 'ro');
})->with('unwritable collaboration documents');

it('refuses a store over content that cannot be saved back', function (Closure $node, string $code): void {
    $node = $node();
    $before = Hub::aggregate($node);

    Hub::call('store', Hub::storeBody($node, 'room-without-widget'), Hub::token($node))
        ->assertStatus(409)
        ->assertJsonPath('code', $code);

    $after = Hub::aggregate($node);
    expect($after->content)->toBe($before->content)
        ->and($after->yjs_state)->toBeNull()
        ->and($after->schema_version)->toBe($before->schema_version)
        ->and($after->state_version)->toBe($before->state_version);
})->with('unwritable collaboration documents');

it('refuses every hub call for a type that cannot collaborate', function (): void {
    DocumentTestHelpers::installTypes([RichTextDocumentType::class, MarkdownDocumentType::class, AcmeKanbanV2Type::class]);
    $node = Hub::document(content: OpaqueContent::fromArray(['columns' => []]), type: 'acme/kanban', schemaVersion: 2);

    foreach (['authenticate', 'load'] as $endpoint) {
        Hub::call($endpoint, ['room' => Hub::room($node)], Hub::token($node))
            ->assertStatus(409)
            ->assertJsonPath('code', 'collaboration_unavailable');
    }
    Hub::call('store', Hub::storeBody($node, 'board-room', ['columns' => []]), Hub::token($node))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_unavailable');
});

it('decodes a store projection with the document type\'s own schema', function (): void {
    $empty = ['type' => 'doc', 'content' => []];
    $node = Hub::document(content: TipTapContent::fromArray($empty, TipTapSchema::markdown()), type: 'vaulter/markdown');

    // A table is rich text, not markdown: storing it would leave the document degraded.
    $table = ['type' => 'doc', 'content' => [
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell']]]]],
    ]];

    Hub::call('store', Hub::storeBody($node, 'markdown-room', $table), Hub::token($node))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_document_content');

    $state = Hub::aggregate($node);
    expect($state->content)->toBe($empty)
        ->and($state->yjs_state)->toBeNull();
});
