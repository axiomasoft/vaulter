<?php

declare(strict_types=1);

use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\Compaction;
use Vaulter\Collaboration\Tests\Support\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The content of a document is a resource with a tag (`"{nodeId}.{epoch}.{stateVersion}"`): an append of the collaboration hub
 * makes it pending until a checkpoint covers it, the checkpoint moves it under a reader's feet exactly like another writer would,
 * and a restore moves the generation, which fences the old room.
 */
beforeEach(function (): void {
    Hub::configure();
    config()->set('vaulter-collaboration.compaction.trigger_updates', 1);
    $this->node = Hub::document(level: RoleRegistry::WRITER, actorId: 1);
    $this->id = (string) $this->node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
});

it('refuses a PUT after a hub append landed between the read and the write', function (): void {
    $tag = Docs::etag($this->id);

    Hub::call('append', Hub::seedBody($this->node, 'hub-state'), Hub::token($this->node))->assertOk();

    // Pending: the saved edit is in no projection yet, so the PUT neither reads nor overwrites a stale one.
    Docs::put($this->id, Docs::doc('Late editor'), $tag)
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_state_pending');

    // Its checkpoint moved the content on: the reader's tag is stale.
    Compaction::publish(Compaction::leaseOf($this->node), overrides: ['content' => Hub::tiptap('From the hub')]);
    Docs::put($this->id, Docs::doc('Late editor'), $tag)
        ->assertStatus(412)
        ->assertJsonPath('code', 'document_state_mismatch');
    expect(Hub::aggregate($this->node)->content['content'][0]['content'][0]['text'])->toBe('From the hub');
});

it('moves the collaboration epoch on a restore, so the old room is fenced off', function (): void {
    $first = $this->getJson(Docs::url('documents/'.$this->id.'/revisions'))->json('data.0.id');
    $epoch = Hub::aggregate($this->node)->collab_epoch;

    $this->postJson(Docs::url('documents/'.$this->id.'/revisions/'.$first.'/restore'), [], Docs::ifMatch($this->id))
        ->assertOk()
        ->assertJsonPath('data.collab_epoch', $epoch + 1);

    Hub::call('append', Hub::appendBody($this->node, 'old-room', generation: $epoch), Hub::token($this->node, epoch: $epoch))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');
});

it('answers the hub of a document whose generation moved on a JSON replace with the epoch conflict', function (): void {
    Hub::call('append', Hub::seedBody($this->node, 'first-room'), Hub::token($this->node))->assertOk();
    Compaction::publish(Compaction::leaseOf($this->node));
    $epoch = Hub::aggregate($this->node)->collab_epoch;

    Docs::put($this->id, Docs::doc('Replaced outside the room'))->assertOk();

    expect(Hub::aggregate($this->node)->collab_epoch)->toBe($epoch + 1);
    Hub::call('append', Hub::appendBody($this->node, 'stale-room', generation: $epoch), Hub::token($this->node, epoch: $epoch))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');
});
