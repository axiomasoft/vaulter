<?php

declare(strict_types=1);

use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The revision history of a document: checkpoints are listed, read whole, pinned and
 * restored; a restore is a content write, so it needs the content's tag.
 */
beforeEach(function (): void {
    Granted::configure();
    $this->node = Granted::document(level: RoleRegistry::WRITER, actorId: 1);
    $this->id = (string) $this->node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
});

it('lists revisions newest first with a cursor and reads one with its content', function (): void {
    $first = $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->assertOk()->json('data.0.id');
    $named = $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'Second'])->assertCreated()->json('data.id');

    $page = $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=1'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $named)
        ->assertJsonPath('data.0.label', 'Second')
        ->assertJsonPath('data.0.is_head', true)
        ->assertJsonPath('meta.per_page', 1);
    expect($page->json('meta.next_cursor'))->toBeString()
        ->and($page->json('data.0'))->not->toHaveKey('content');

    $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=1&cursor='.urlencode($page->json('meta.next_cursor'))))
        ->assertOk()
        ->assertJsonPath('data.0.id', $first);
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions/'.$first))
        ->assertOk()
        ->assertJsonPath('data.id', $first)
        ->assertJsonPath('data.content.content.0.content.0.text', 'Draft')
        ->assertJsonMissingPath('data.snapshot_label');
});

it('orders and pages revisions by number, not by a clock that stepped back', function (): void {
    $this->travelTo(now()->addHour());
    $later = $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'clock ahead'])->assertCreated()->json('data.id');
    $this->travelBack();
    $newest = $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'clock back'])->assertCreated()->json('data.id');

    $page = $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=1'))->assertOk()->assertJsonPath('data.0.id', $newest);
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=1&cursor='.urlencode($page->json('meta.next_cursor'))))
        ->assertOk()
        ->assertJsonPath('data.0.id', $later);
});

it('refuses a per_page out of range and an unknown revision', function (): void {
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=0'))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions?per_page=201'))->assertStatus(422);
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions/999999'))->assertNotFound()->assertJsonPath('code', 'document_revision_not_found');
});

it('pins and releases a revision without touching its label', function (): void {
    $id = $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'Keep me'])->assertCreated()->json('data.id');

    $this->patchJson(Docs::url('documents/'.$this->id.'/revisions/'.$id), ['keep_forever' => true])
        ->assertOk()
        ->assertJsonPath('data.keep_forever', true)
        ->assertJsonPath('data.label', 'Keep me');
    $this->patchJson(Docs::url('documents/'.$this->id.'/revisions/'.$id), ['label' => 'Renamed', 'keep_forever' => false])
        ->assertOk()
        ->assertJsonPath('data.keep_forever', false)
        ->assertJsonPath('data.label', 'Keep me');
    $this->patchJson(Docs::url('documents/'.$this->id.'/revisions/'.$id), [])->assertStatus(422)->assertJsonValidationErrors('keep_forever');
});

it('restores with the content tag: 428 without it, 412 when stale, the old content when current', function (): void {
    $first = $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->json('data.0.id');
    $stale = Docs::etag($this->id);
    Docs::put($this->id, Docs::doc('Changed'), $stale)->assertOk();
    $url = Docs::url('documents/'.$this->id.'/revisions/'.$first.'/restore');

    $this->postJson($url)->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $this->postJson($url, [], ['If-Match' => $stale])->assertStatus(412)->assertJsonPath('code', 'document_state_mismatch');
    $restored = $this->postJson($url, [], Docs::ifMatch($this->id))
        ->assertOk()
        ->assertJsonPath('data.content.content.0.content.0.text', 'Draft');

    expect($restored->headers->get('ETag'))->toBe(Docs::etag($this->id));
});

it('moves the collaboration epoch on a restore, so a room of the old generation can no longer write', function (): void {
    $first = $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->json('data.0.id');
    $epoch = Granted::aggregate($this->node)->collab_epoch;

    $this->postJson(Docs::url('documents/'.$this->id.'/revisions/'.$first.'/restore'), [], Docs::ifMatch($this->id))
        ->assertOk()
        ->assertJsonPath('data.collab_epoch', $epoch + 1);
});

it('lets a reader read the history and refuses it every write', function (): void {
    AccessTestHelpers::grant($this->node, RoleRegistry::READER, subjectId: 2);
    $first = $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->json('data.0.id');
    $this->actingAs(AccessTestHelpers::user(2));

    $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->assertOk();
    $this->getJson(Docs::url('documents/'.$this->id.'/revisions/'.$first))->assertOk();
    $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'No'])->assertForbidden();
    $this->patchJson(Docs::url('documents/'.$this->id.'/revisions/'.$first), ['keep_forever' => true])->assertForbidden();
    $this->postJson(Docs::url('documents/'.$this->id.'/revisions/'.$first.'/restore'), [], ['If-Match' => '"x"'])->assertForbidden();
});

it('answers a caller without a grant 404 on every revision route', function (): void {
    $this->actingAs(AccessTestHelpers::user(9));

    $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->postJson(Docs::url('documents/'.$this->id.'/revisions'), ['label' => 'No'])->assertNotFound();
    $this->postJson(Docs::url('documents/'.$this->id.'/revisions/1/restore'), [], ['If-Match' => '"x"'])->assertNotFound();
});

it('refuses a malformed or mismatched revisions cursor with 422 instead of failing the query', function (): void {
    $forge = static fn (array $parameters): string => rtrim(strtr(base64_encode((string) json_encode($parameters)), '+/', '-_'), '=');
    $bad = [
        // A cursor of the earlier timestamp order: no revision number to seek from.
        $forge(['created_at' => '2026-01-01 00:00:00', 'id' => 1, '_pointsToNextItems' => true]),
        $forge(['number' => 'abc', '_pointsToNextItems' => true]),
        $forge(['number' => -1, '_pointsToNextItems' => true]),
        $forge(['number' => 1, '_pointsToNextItems' => 'yes']),
        'not-a-cursor',
    ];

    foreach ($bad as $cursor) {
        $this->getJson(Docs::url('documents/'.$this->id.'/revisions?cursor='.urlencode($cursor)))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);
    }
});
