<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Documents\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/** Comments hang off any node of a drive; the routes are always there and the operations decide. */
beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document(level: RoleRegistry::COMMENTER, actorId: 1);
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

    $this->postJson($this->url.'/'.$first.'/resolve')->assertOk()->assertJsonPath('data.resolved_by', '1');
    $this->deleteJson($this->url.'/'.$reply)->assertNoContent();
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
});

it('validates the body and keeps ids as strings', function (): void {
    $this->postJson($this->url, [])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->postJson($this->url, ['body' => str_repeat('x', 5001)])->assertStatus(422)->assertJsonValidationErrors('body');
    $this->postJson($this->url, ['body' => 'x', 'parent_id' => 'missing'])->assertNotFound()->assertJsonPath('code', 'comment_not_found');
    expect($this->postJson($this->url, ['body' => 'ok'])->json('data.id'))->toBeString();
});

it('lets a reader list the thread but not write in it', function (): void {
    $reader = Hub::document(level: RoleRegistry::READER, actorId: 1);
    $url = Docs::url('nodes/'.$reader->getKey().'/comments');

    $this->getJson($url)->assertOk();
    $this->postJson($url, ['body' => 'no'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('answers a comment of another node 404 comment_not_found and a stranger 404 node_not_found', function (): void {
    $id = $this->postJson($this->url, ['body' => 'mine'])->json('data.id');
    $other = Hub::document(level: RoleRegistry::OWNER, actorId: 1);
    $otherUrl = Docs::url('nodes/'.$other->getKey().'/comments');

    $this->postJson($otherUrl.'/'.$id.'/resolve')->assertNotFound()->assertJsonPath('code', 'comment_not_found');
    $this->deleteJson($otherUrl.'/'.$id)->assertNotFound()->assertJsonPath('code', 'comment_not_found');

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
