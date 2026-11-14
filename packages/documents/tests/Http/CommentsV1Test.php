<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/** Comments hang off any node of a drive; the routes are always there and the operations decide. */
beforeEach(function (): void {
    Granted::configure();
    $this->node = Granted::document(level: RoleRegistry::COMMENTER, actorId: 1);
    $this->url = Docs::url('nodes/'.$this->node->getKey().'/comments');
    $this->actingAs(AccessTestHelpers::user(1));
});

it('adds, replies, lists in pages, resolves and deletes a comment', function (): void {
    $first = $this->postJson($this->url, ['body' => 'first', 'anchor' => ['from' => 1, 'to' => 4]])
        ->assertCreated()
        ->assertJsonPath('data.body', 'first')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.author.id', '1')
        ->assertJsonPath('data.resolved_at', null)
        ->json('data.id');
    $reply = $this->postJson($this->url, ['body' => 'reply', 'parent_id' => $first])->assertCreated()->assertJsonPath('data.parent_id', $first)->json('data.id');

    $page = $this->getJson($this->url.'?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.id', $first)
        ->assertJsonPath('meta.per_page', 1);
    $this->getJson($this->url.'?per_page=1&cursor='.urlencode($page->json('meta.next_cursor')))->assertOk()->assertJsonPath('data.0.id', $reply);

    $firstTag = $this->getJson($this->url)->assertOk()->json('data.0.etag');
    $replyTag = $this->getJson($this->url)->assertOk()->json('data.1.etag');
    $this->postJson($this->url.'/'.$first.'/resolve', [], ['If-Match' => $firstTag])->assertOk()->assertJsonPath('data.resolved_by', '1');
    $this->deleteJson($this->url.'/'.$reply, [], ['If-Match' => $replyTag])->assertNoContent();
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
});

it('validates the body and keeps ids as strings', function (): void {
    $this->postJson($this->url, [])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->postJson($this->url, ['body' => str_repeat('x', 5001)])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->postJson($this->url, ['body' => 'x', 'parent_id' => 'missing'])->assertNotFound()->assertJsonPath('code', 'comment_not_found');
    expect($this->postJson($this->url, ['body' => 'ok'])->json('data.id'))->toBeString();
});

it('bounds the encoded anchor to 16384 bytes without changing an accepted anchor', function (): void {
    $anchor = ['value' => str_repeat('a', 16372)];
    $this->postJson($this->url, ['body' => 'boundary', 'anchor' => $anchor])
        ->assertCreated()->assertJsonPath('data.anchor', $anchor);

    $this->postJson($this->url, ['body' => 'too large', 'anchor' => ['value' => str_repeat('a', 16373)]])
        ->assertUnprocessable()->assertJsonValidationErrors('anchor');
    $this->postJson($this->url, ['body' => 'encoded bytes', 'anchor' => ['value' => str_repeat('я', 2731)]])
        ->assertUnprocessable()->assertJsonValidationErrors('anchor');

    expect(NodeComment::query()->count())->toBe(1);
});

it('bounds an anchor to sixteen JSON levels and accepts the boundary', function (): void {
    $anchor = ['offset' => 1];
    for ($level = 1; $level < 16; $level++) {
        $anchor = ['range' => $anchor];
    }
    $this->postJson($this->url, ['body' => 'boundary', 'anchor' => $anchor])
        ->assertCreated()->assertJsonPath('data.anchor', $anchor);
    $this->postJson($this->url, ['body' => 'too deep', 'anchor' => ['range' => $anchor]])
        ->assertUnprocessable()->assertJsonValidationErrors('anchor');

    expect(NodeComment::query()->count())->toBe(1);
});

it('lets a reader list the thread but not write in it', function (): void {
    $reader = Granted::document(level: RoleRegistry::READER, actorId: 1);
    $url = Docs::url('nodes/'.$reader->getKey().'/comments');

    $this->getJson($url)->assertOk();
    $this->postJson($url, ['body' => 'no'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('answers a comment of another node 404 comment_not_found and a stranger 404 node_not_found', function (): void {
    $id = $this->postJson($this->url, ['body' => 'mine'])->json('data.id');
    $other = Granted::document(level: RoleRegistry::OWNER, actorId: 1);
    $otherUrl = Docs::url('nodes/'.$other->getKey().'/comments');

    $this->postJson($otherUrl.'/'.$id.'/resolve', [], ['If-Match' => '"x"'])->assertNotFound()->assertJsonPath('code', 'comment_not_found');
    $this->deleteJson($otherUrl.'/'.$id, [], ['If-Match' => '"x"'])->assertNotFound()->assertJsonPath('code', 'comment_not_found');

    $this->actingAs(AccessTestHelpers::user(9));
    $this->getJson($this->url)->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('refuses a visible node with 403 profile_restriction when comments are switched off globally', function (): void {
    config()->set('vaulter-documents.comments.enabled', false);

    $this->getJson($this->url)->assertForbidden()->assertJsonPath('code', 'profile_restriction');
    $this->postJson($this->url, ['body' => 'x'])->assertForbidden();
    // An invisible node stays an ordinary 404: the flag does not reveal it.
    $this->actingAs(AccessTestHelpers::user(9));
    $this->getJson($this->url)->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('tags a comment with its id and version, and the resolution raises the version and the tag', function (): void {
    $added = $this->postJson($this->url, ['body' => 'tagged'])->assertCreated()->assertJsonPath('data.version', 0);
    $id = $added->json('data.id');
    $tag = '"comment.'.$id.'.0"';

    expect($added->headers->get('ETag'))->toBe($tag)->and($added->json('data.etag'))->toBe($tag);
    $resolved = $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => $tag])->assertOk()->assertJsonPath('data.version', 1);
    expect($resolved->headers->get('ETag'))->toBe('"comment.'.$id.'.1"')->and($resolved->json('data.etag'))->toBe('"comment.'.$id.'.1"');
});

it('refuses a stale tag with 412 stale_write and changes nothing', function (): void {
    $id = $this->postJson($this->url, ['body' => 'once'])->assertCreated()->json('data.id');
    $tag = '"comment.'.$id.'.0"';
    $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => $tag])->assertOk();

    $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => $tag])->assertStatus(412)->assertJsonPath('code', 'stale_write');
    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => $tag])->assertStatus(412)->assertJsonPath('code', 'stale_write');
    $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => 'W/'.$tag])->assertStatus(412);

    expect(NodeComment::query()->findOrFail($id)->version)->toBe(1);
});

it('replays a comment add, a resolution and a deletion under their Idempotency-Key, even with a stale If-Match', function (): void {
    $added = $this->postJson($this->url, ['body' => 'keyed'], ['Idempotency-Key' => 'add-1'])->assertCreated();
    $again = $this->postJson($this->url, ['body' => 'keyed'], ['Idempotency-Key' => 'add-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $id = $added->json('data.id');
    expect($again->json('data.id'))->toBe($id)->and(NodeComment::query()->count())->toBe(1);

    $tag = $added->headers->get('ETag');
    $resolved = $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => $tag, 'Idempotency-Key' => 'resolve-1'])->assertOk();
    // The retry repeats the If-Match of the first request, stale since it committed.
    $replayed = $this->postJson($this->url.'/'.$id.'/resolve', [], ['If-Match' => $tag, 'Idempotency-Key' => 'resolve-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($replayed->json('data'))->toBe($resolved->json('data'))->and(NodeComment::query()->findOrFail($id)->version)->toBe(1);

    $current = $resolved->headers->get('ETag');
    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => $current, 'Idempotency-Key' => 'delete-1'])->assertNoContent();
    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => $current, 'Idempotency-Key' => 'delete-1'])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
    expect(NodeComment::query()->count())->toBe(0);
    // Without a key the comment is gone.
    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => $current])->assertNotFound()->assertJsonPath('code', 'comment_not_found');
});

it('refuses the same Idempotency-Key for another comment', function (): void {
    $this->postJson($this->url, ['body' => 'one'], ['Idempotency-Key' => 'k'])->assertCreated();

    $this->postJson($this->url, ['body' => 'two'], ['Idempotency-Key' => 'k'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    expect(NodeComment::query()->count())->toBe(1);
});

it('answers a stranger who replays a deletion 404, not the stored receipt', function (): void {
    $id = $this->postJson($this->url, ['body' => 'mine'])->assertCreated()->json('data.id');
    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => '"comment.'.$id.'.0"', 'Idempotency-Key' => 'del'])->assertNoContent();

    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->where('subject_id', 1)->delete());

    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => '"comment.'.$id.'.0"', 'Idempotency-Key' => 'del'])->assertNotFound()->assertJsonPath('code', 'node_not_found');
});
