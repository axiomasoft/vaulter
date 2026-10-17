<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Documents\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Documents\Exceptions\CollaborationSeedConflictException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentStateTooLargeException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The hub protocol end to end at the HTTP boundary: load → seed → store behind the
 * epoch fence, read-only enforced by the backend, and every call re-checking the live
 * document (trash, drive status, tenant, rights). The hub process itself is covered by
 * hocuspocus-server/test and scripts/collab-e2e.sh.
 */
beforeEach(function (): void {
    Hub::configure();
});

function hubRestore(Node $node, DocumentRevision $revision): DocumentRevision
{
    return app(RestoreDocumentRevisionAction::class)->execute(
        DocumentTestHelpers::context($node, actorId: 1),
        new RestoreDocumentRevisionInput(node: $node, revision: $revision),
    );
}

// ── load and seed ───────────────────────────────────────────────────────────────────

it('loads the JSON to seed from while the document has no Yjs state, then the state', function (): void {
    $node = Hub::document();

    Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('state', null)
        ->assertJsonPath('content.content.0.content.0.text', 'Draft')
        ->assertJsonPath('epoch', 0)
        ->assertJsonPath('schemaVersion', 1)
        ->assertJsonPath('stateVersion', 1)
        ->assertJsonPath('type', 'vaulter/rich-text');

    Hub::call('store', Hub::storeBody($node, 'seeded-state', Hub::tiptap('Draft'), seed: true), Hub::token($node))
        ->assertOk();

    Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('state', base64_encode('seeded-state'))
        ->assertJsonPath('content', null)
        ->assertJsonPath('stateVersion', 2);
});

it('lets a read-only connection load but never store', function (): void {
    $node = Hub::document(level: RoleRegistry::READER);

    Hub::call('load', ['room' => Hub::room($node)], Hub::token($node, mode: 'ro'))->assertOk();
    // Even a token that claims rw writes nothing for a user who now only reads.
    Hub::call('store', Hub::storeBody($node, 'reader-edit'), Hub::token($node, mode: 'rw'))->assertForbidden();
    Hub::call('store', Hub::storeBody($node, 'reader-edit'), Hub::token($node, mode: 'ro'))->assertForbidden();

    expect(Hub::aggregate($node)->yjs_state)->toBeNull();
});

it('accepts exactly one seed per generation', function (): void {
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'first-seed', seed: true), Hub::token($node))->assertOk();
    Hub::call('store', Hub::storeBody($node, 'second-seed', seed: true), Hub::token($node))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_seed_conflict');

    expect(Hub::aggregate($node)->yjs_state)->toBe('first-seed');
});

it('stores and seeds the projection the hub emits for a link, an image and a table', function (): void {
    // Exactly what hocuspocus-server projects (TiptapTransformer.fromYdoc): the link mark
    // carries every attribute @tiptap/extension-link declares, `title: null` included.
    $projection = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'link', 'attrs' => [
            'href' => 'https://example.com', 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'class' => null, 'title' => null,
        ]]]]]],
        ['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png', 'alt' => 'a']],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableHeader', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph']]],
            ['type' => 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1], 'content' => [['type' => 'paragraph']]],
        ]]]],
    ]];
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'seed-with-link', $projection, seed: true), Hub::token($node))->assertOk();
    Hub::call('store', Hub::storeBody($node, 'edit-with-link', $projection), Hub::token($node))->assertOk();

    expect(Hub::aggregate($node)->content['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe('https://example.com');
});

it('refuses a seed built before a REST update and loses no text', function (): void {
    $node = Hub::document(level: RoleRegistry::WRITER, actorId: 1);
    $loaded = Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))->assertOk();
    $version = $loaded->json('stateVersion');

    // The document has no Yjs state, so this update does not move the epoch.
    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(1));
    Documents::in($handle)->update($node->id, Hub::tiptap('Written over REST'));
    expect(Hub::aggregate($node)->collab_epoch)->toBe(0);

    // The hub still seeds from what it loaded ('Draft'): refused, 'Written over REST' survives.
    Hub::call('store', Hub::storeBody($node, 'stale-seed', Hub::tiptap('Draft'), seed: true, stateVersion: $version), Hub::token($node))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_seed_conflict')
        ->assertJsonPath('meta.context.expected_state_version', $version)
        ->assertJsonPath('meta.context.actual_state_version', Hub::aggregate($node)->state_version);
    $after = Hub::aggregate($node);
    expect($after->yjs_state)->toBeNull()
        ->and($after->content['content'][0]['content'][0]['text'])->toBe('Written over REST');

    // The hub loads again and seeds from the current content.
    $reloaded = Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('content.content.0.content.0.text', 'Written over REST');
    Hub::call('store', Hub::storeBody($node, 'fresh-seed', Hub::tiptap('Written over REST'), seed: true, stateVersion: $reloaded->json('stateVersion')), Hub::token($node))
        ->assertOk();
    expect(Hub::aggregate($node)->yjs_state)->toBe('fresh-seed');
});

it('rejects a malformed seed state_version', function (): void {
    $node = Hub::document();

    foreach (['3', -1, 1.5, true] as $bad) {
        Hub::call('store', [...Hub::storeBody($node, 'seed', seed: true), 'state_version' => $bad], Hub::token($node))->assertStatus(422);
    }

    expect(Hub::aggregate($node)->yjs_state)->toBeNull();
});

// ── the epoch fence ─────────────────────────────────────────────────────────────────

it('fences off a store from the room opened before a restore (V12)', function (): void {
    Event::fake([DocumentRestored::class]);
    $node = Hub::document();
    $first = DocumentTestHelpers::head($node);
    $oldToken = Hub::token($node);

    Hub::call('store', Hub::storeBody($node, 'live-edit', Hub::tiptap('Live edit')), $oldToken)->assertOk();
    hubRestore($node, $first);

    $restored = Hub::aggregate($node);
    expect($restored->collab_epoch)->toBe(1)
        ->and($restored->content['content'][0]['content'][0]['text'])->toBe('Draft');
    Event::assertDispatched(DocumentRestored::class, static fn (DocumentRestored $event): bool => $event->epoch === 1
        && $event->restoredFrom->is($first)
        && $event->stateVersion === $restored->state_version);

    // The hub's debounced store of the old room arrives late: refused, nothing changes.
    Hub::call('store', Hub::storeBody($node, 'late-old-edit', Hub::tiptap('Late'), epoch: 0), $oldToken)
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch')
        ->assertJsonPath('meta.context.expected', 0)
        ->assertJsonPath('meta.context.actual', 1);
    // The old room can neither reconnect nor load.
    foreach (['authenticate', 'load'] as $endpoint) {
        Hub::call($endpoint, ['room' => Hub::room($node, 0)], $oldToken)
            ->assertStatus(409)
            ->assertJsonPath('code', 'collaboration_epoch_mismatch');
    }

    $after = Hub::aggregate($node);
    expect($after->content)->toBe($restored->content)
        ->and($after->yjs_state)->toBe($restored->yjs_state)
        ->and($after->state_version)->toBe($restored->state_version);

    // The new generation loads the restored content and writes again.
    Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('epoch', 1);
    Hub::call('store', Hub::storeBody($node, 'new-room-edit'), Hub::token($node))->assertOk();
});

it('fences a past epoch inside the locked write itself, not only at the endpoint', function (): void {
    $node = Hub::document();
    hubRestore($node, DocumentTestHelpers::head($node));
    $before = Hub::aggregate($node);

    expect(fn () => app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
        node: $node, yjsState: 'stale-room', content: Hub::tiptap('stale'), expectedEpoch: 0,
    )))->toThrow(CollaborationEpochMismatchException::class);

    expect(Hub::aggregate($node)->state_version)->toBe($before->state_version);
});

it('moves the epoch on an external JSON replace of a document with Yjs state', function (): void {
    $node = Hub::document(level: RoleRegistry::WRITER, actorId: 1);
    Hub::call('store', Hub::storeBody($node, 'room-state'), Hub::token($node))->assertOk();

    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(1));
    Documents::in($handle)->update($node->id, Hub::tiptap('Replaced by API'));

    $document = Hub::aggregate($node);
    expect($document->collab_epoch)->toBe(1)
        // The hub seeds the next room from the replaced JSON.
        ->and($document->yjs_state)->toBeNull();
    Hub::call('store', Hub::storeBody($node, 'stale', epoch: 0), Hub::token($node, epoch: 0))
        ->assertStatus(409);
});

// ── live checks on every call ───────────────────────────────────────────────────────

it('takes a revoked Edit into account on the next call (OPUS D24)', function (): void {
    $node = Hub::document(level: RoleRegistry::WRITER);
    $token = Hub::token($node);
    Hub::call('authenticate', ['room' => Hub::room($node)], $token)->assertJsonPath('mode', 'rw');

    $grants = config('vaulter-drive.models.grant');
    app(VaulterDatabase::class)->write(fn () => $grants::query()->where('node_id', $node->id)->delete());
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 1);

    Hub::call('authenticate', ['room' => Hub::room($node)], $token)->assertOk()->assertJsonPath('mode', 'ro');
    Hub::call('store', Hub::storeBody($node, 'after-revoke'), $token)->assertForbidden();
});

it('shares an archived drive read-only', function (): void {
    $node = Hub::document();
    app(VaulterDatabase::class)->write(fn () => $node->storage()->update(['status' => 'archived']));

    Hub::call('authenticate', ['room' => Hub::room($node)], Hub::token($node))
        ->assertOk()
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('mode', 'ro');
    Hub::call('load', ['room' => Hub::room($node)], Hub::token($node))->assertOk();
    Hub::call('store', Hub::storeBody($node, 'archived-edit'), Hub::token($node))->assertForbidden();
});

it('answers node_not_found for a trashed document, another tenant or a drive being deleted', function (Closure $change): void {
    $node = Hub::document();
    $token = Hub::token($node);
    $change($node);

    foreach (['authenticate', 'load'] as $endpoint) {
        Hub::call($endpoint, ['room' => Hub::room($node, 0)], $token)
            ->assertNotFound()
            ->assertJsonPath('code', 'node_not_found');
    }
    Hub::call('store', Hub::storeBody($node, 'gone', epoch: 0), $token)->assertNotFound();
})->with([
    'trashed' => [static fn (Node $node) => Vaulter::drive($node->storage()->firstOrFail())->asSystem('hub test')->nodes()->trash($node->id)],
    'drive deleting' => [static fn (Node $node) => app(VaulterDatabase::class)->write(fn () => $node->storage()->update(['status' => 'deleting']))],
    'token of another tenant' => [static fn (Node $node) => app(VaulterDatabase::class)->write(fn () => Node::query()->whereKey($node->id)->update(['tenant_id' => 'tenant-b']))],
]);

it('refuses a body that names another room than the token', function (): void {
    $node = Hub::document();

    Hub::call('authenticate', ['room' => Hub::room($node).'0'], Hub::token($node))->assertForbidden();
    Hub::call('load', [], Hub::token($node))->assertForbidden();
});

// ── limits ──────────────────────────────────────────────────────────────────────────

it('refuses a state above collaboration.max_state_bytes with 413 and writes nothing', function (): void {
    config()->set('vaulter-documents.collaboration.max_state_bytes', 8);
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, str_repeat('y', 9)), Hub::token($node))
        ->assertStatus(413)
        ->assertJsonPath('code', 'document_state_too_large');
    Hub::call('store', Hub::storeBody($node, str_repeat('y', 8)), Hub::token($node))->assertOk();
});

it('refuses a projection above the content limit', function (): void {
    config()->set('vaulter-documents.content.max_size_kb', 1);
    $node = Hub::document();

    Hub::call('store', Hub::storeBody($node, 'state', Hub::tiptap(str_repeat('x', 2048))), Hub::token($node))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_document_content');

    expect(Hub::aggregate($node)->yjs_state)->toBeNull();
});

// ── error contract ──────────────────────────────────────────────────────────────────

it('keeps the stable codes and statuses of the collaboration errors', function (Throwable $exception, string $code, int $status): void {
    expect($exception->problemCode())->toBe($code)
        ->and($exception->httpStatus())->toBe($status);
})->with([
    'epoch mismatch' => [new CollaborationEpochMismatchException(1, 2), 'collaboration_epoch_mismatch', 409],
    'seed conflict' => [new CollaborationSeedConflictException('42'), 'collaboration_seed_conflict', 409],
    'unavailable' => [CollaborationUnavailableException::disabled(), 'collaboration_unavailable', 409],
    'state too large' => [new DocumentStateTooLargeException(10, 8), 'document_state_too_large', 413],
]);
