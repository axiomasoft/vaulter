<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;

/**
 * The content of a document is its own resource: GET carries a tag made of the
 * generation (`"{nodeId}.{epoch}.{stateVersion}"`), PUT is refused without it or with a
 * stale one, and a write by another editor moves the generation under a reader's feet.
 */
beforeEach(function (): void {
    Granted::configure();
});

/** @return array{0: Node, 1: string} a document under a writer (user 1) and a reader (user 2), and its id */
function sharedDocument(): array
{
    $node = Granted::document(level: RoleRegistry::WRITER, actorId: 1);
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 2);

    return [$node, (string) $node->getKey()];
}

it('answers a reader and a writer with the same body and the same tag', function (): void {
    [$node, $id] = sharedDocument();

    $this->actingAs(AccessTestHelpers::user(1));
    $writer = $this->getJson(Docs::url('documents/'.$id.'/content'))->assertOk();
    $this->actingAs(AccessTestHelpers::user(2));
    $reader = $this->getJson(Docs::url('documents/'.$id.'/content'))->assertOk();

    $epoch = Granted::aggregate($node)->collab_epoch;
    $version = Granted::aggregate($node)->state_version;
    expect($reader->json())->toBe($writer->json())
        ->and($reader->headers->get('ETag'))->toBe($writer->headers->get('ETag'))
        ->and($writer->headers->get('ETag'))->toBe('"'.$id.'.'.$epoch.'.'.$version.'"')
        ->and($writer->json('data'))->toHaveKeys(['type', 'schema_version', 'state_version', 'collab_epoch', 'revision_id', 'content'])
        ->and(array_keys($writer->json('data')))->toHaveCount(6);
});

it('answers 304 to a matching If-None-Match and a fresh body to a stale one', function (): void {
    [, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $tag = Docs::etag($id);

    $this->getJson(Docs::url('documents/'.$id.'/content'), ['If-None-Match' => $tag])
        ->assertStatus(304)
        ->assertHeader('ETag', $tag);
    $this->getJson(Docs::url('documents/'.$id.'/content'), ['If-None-Match' => '"'.$id.'.0.999"'])->assertOk();
});

it('never answers 304 to a caller who lost access, and keeps the 404 uniform', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(2));
    $tag = Docs::etag($id);

    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->where('subject_id', 2)->delete());

    $gone = $this->getJson(Docs::url('documents/'.$id.'/content'), ['If-None-Match' => $tag])->assertNotFound();
    $unknown = $this->getJson(Docs::url('documents/no-such-document/content'))->assertNotFound();

    expect(Docs::problemWithoutRequestId($gone))->toBe(Docs::problemWithoutRequestId($unknown));
});

it('requires If-Match to write: 428 without it or with *, 412 for a weak or stale tag', function (): void {
    [, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $url = Docs::url('documents/'.$id.'/content');
    $tag = Docs::etag($id);

    $this->putJson($url, ['content' => Docs::doc('x')])->assertStatus(428)->assertJsonPath('code', 'precondition_required')->assertJsonPath('meta.header', 'If-Match');
    $this->putJson($url, ['content' => Docs::doc('x')], ['If-Match' => '*'])->assertStatus(428)->assertJsonPath('meta.reason', 'wildcard_not_allowed');
    $this->putJson($url, ['content' => Docs::doc('x')], ['If-Match' => 'W/'.$tag])->assertStatus(412)->assertJsonPath('code', 'document_state_mismatch');
    $this->putJson($url, ['content' => Docs::doc('x')], ['If-Match' => '"'.$id.'.0.999"'])->assertStatus(412);

    $this->getJson($url)->assertHeader('ETag', $tag);
});

it('saves with the current tag, moves the state version by one and answers the new tag', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $before = Granted::aggregate($node)->state_version;
    $tag = Docs::etag($id);

    $saved = Docs::put($id, Docs::doc('Saved'), $tag)
        ->assertOk()
        ->assertJsonPath('data.state_version', $before + 1)
        ->assertJsonPath('data.content.content.0.content.0.text', 'Saved');

    expect($saved->headers->get('ETag'))->not->toBe($tag)
        ->and($saved->headers->get('ETag'))->toBe(Docs::etag($id));
});

it('refuses a PUT after another writer landed between the read and the write', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $tag = Docs::etag($id);

    Docs::put($id, Docs::doc('From another writer'), $tag)->assertOk();

    Docs::put($id, Docs::doc('Late editor'), $tag)
        ->assertStatus(412)
        ->assertJsonPath('code', 'document_state_mismatch');
    expect(Granted::aggregate($node)->content['content'][0]['content'][0]['text'])->toBe('From another writer');
});

it('lets a previewer read the content and refuses its write before any tag is compared', function (): void {
    $node = Granted::document(level: RoleRegistry::PREVIEWER, actorId: 3);
    $id = (string) $node->getKey();
    $this->actingAs(AccessTestHelpers::user(3));

    $this->getJson(Docs::url('documents/'.$id.'/content'))->assertOk();
    $this->putJson(Docs::url('documents/'.$id.'/content'), ['content' => Docs::doc('x')])->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->putJson(Docs::url('documents/'.$id.'/content'), ['content' => Docs::doc('x')], ['If-Match' => '"stale"'])->assertForbidden();
});

it('keeps the content tag when the document is renamed', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $tag = Docs::etag($id);

    $drive = Vaulter::drive($node->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(1));
    $drive->nodes()->rename($id, 'Renamed');

    expect(Docs::etag($id))->toBe($tag);
    Docs::put($id, Docs::doc('Still valid'), $tag)->assertOk();
});

it('hides an unknown document and one of another kind consistently', function (): void {
    [, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $folder = AccessTestHelpers::folder(Drive::query()->firstOrFail(), null, 'plain');
    AccessTestHelpers::grant($folder, RoleRegistry::OWNER, subjectId: 1);

    $this->getJson(Docs::url('documents/'.$folder->getKey().'/content'))->assertStatus(409)->assertJsonPath('code', 'not_a_document');
    $this->getJson(Docs::url('documents/missing/content'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('marks the content response as private and revalidated on every use', function (): void {
    [, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));

    $response = $this->getJson(Docs::url('documents/'.$id.'/content'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')
        ->and($response->headers->get('Vary'))->toContain('Authorization')
        ->and($response->headers->get('X-Vaulter-Api-Version'))->toBe('1');
});

it('does not reveal the current tag in a 412, so a stale caller cannot rebuild it', function (): void {
    [, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $stale = Docs::etag($id);
    Docs::put($id, Docs::doc('Second'), $stale)->assertOk();
    $current = Docs::etag($id);

    $refused = Docs::put($id, Docs::doc('Stale'), $stale)->assertStatus(412)->assertJsonPath('meta.node_id', $id)->assertJsonMissingPath('meta.context');

    expect($refused->json('meta'))->toBe(['node_id' => $id])
        ->and($refused->headers->get('ETag'))->toBeNull()
        ->and($current)->not->toBe($stale);
});

it('answers an editor on an archived drive 409 drive_archived before any precondition', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $tag = Docs::etag($id);
    app(VaulterDatabase::class)->write(static fn () => Drive::query()->whereKey($node->drive_id)->update(['status' => 'archived']));

    $this->putJson(Docs::url('documents/'.$id.'/content'), ['content' => Docs::doc('x')])->assertStatus(409)->assertJsonPath('code', 'drive_archived');
    $this->putJson(Docs::url('documents/'.$id.'/content'), ['content' => Docs::doc('x')], ['If-Match' => '"stale"'])->assertStatus(409);
    $this->postJson(Docs::url('documents/'.$id.'/revisions/1/restore'), [], ['If-Match' => $tag])->assertStatus(409)->assertJsonPath('code', 'drive_archived');
    $this->getJson(Docs::url('documents/'.$id.'/content'))->assertOk();
});

it('is conditional, not keyed: a repeat of the same PUT after it committed answers 412 and writes nothing more', function (): void {
    [$node, $id] = sharedDocument();
    $this->actingAs(AccessTestHelpers::user(1));
    $tag = Docs::etag($id);

    $first = Docs::put($id, Docs::doc('Once'), $tag)->assertOk();
    $state = Granted::aggregate($node)->state_version;
    $revisions = DocumentRevision::query()->count();
    $events = OutboxEvent::query()->count();

    // The same request again — with or without an Idempotency-Key, which this route does not read.
    Docs::put($id, Docs::doc('Once'), $tag)->assertStatus(412)->assertJsonPath('code', 'document_state_mismatch');
    $this->putJson(Docs::url('documents/'.$id.'/content'), ['content' => Docs::doc('Once')], ['If-Match' => $tag, 'Idempotency-Key' => 'ignored'])
        ->assertStatus(412)->assertHeaderMissing('Idempotent-Replayed');

    expect(Granted::aggregate($node)->state_version)->toBe($state)
        ->and(DocumentRevision::query()->count())->toBe($revisions)
        ->and(OutboxEvent::query()->count())->toBe($events)
        ->and($first->headers->get('ETag'))->toBe(Docs::etag($id));
});

it('creates a document, checkpoints it and restores it under an Idempotency-Key, replaying each once', function (): void {
    $node = Granted::document(level: RoleRegistry::WRITER, actorId: 1);
    $id = (string) $node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
    $storage = $node->storage()->firstOrFail();
    AccessTestHelpers::grant(Node::query()->findOrFail($storage->root_node_id), RoleRegistry::OWNER, scope: GrantScope::Subtree, subjectId: 1);
    $drive = (string) $storage->getKey();

    $created = $this->postJson(Docs::url('drives/'.$drive.'/documents'), ['name' => 'Keyed'], ['Idempotency-Key' => 'doc-1'])->assertCreated();
    $this->postJson(Docs::url('drives/'.$drive.'/documents'), ['name' => 'Keyed'], ['Idempotency-Key' => 'doc-1'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $created->json('data.id'));
    expect(Node::query()->where('name', 'Keyed')->count())->toBe(1);

    $revision = $this->postJson(Docs::url('documents/'.$id.'/revisions'), ['label' => 'v1'], ['Idempotency-Key' => 'rev-1'])->assertCreated();
    $revisions = DocumentRevision::query()->count();
    $this->postJson(Docs::url('documents/'.$id.'/revisions'), ['label' => 'v1'], ['Idempotency-Key' => 'rev-1'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $revision->json('data.id'));
    expect(DocumentRevision::query()->count())->toBe($revisions);

    $tag = Docs::etag($id);
    $restore = Docs::url('documents/'.$id.'/revisions/'.$revision->json('data.id').'/restore');
    $first = $this->postJson($restore, [], ['If-Match' => $tag, 'Idempotency-Key' => 'restore-1'])->assertOk();
    $state = Granted::aggregate($node)->state_version;
    // The retry repeats the tag of the first request, stale since it committed.
    $again = $this->postJson($restore, [], ['If-Match' => $tag, 'Idempotency-Key' => 'restore-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data'))->toBe($first->json('data'))->and(Granted::aggregate($node)->state_version)->toBe($state);
    // Without a key the stale tag stands.
    $this->postJson($restore, [], ['If-Match' => $tag])->assertStatus(412)->assertJsonPath('code', 'document_state_mismatch');
});
