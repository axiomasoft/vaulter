<?php

declare(strict_types=1);

use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The content of a document is a resource with a tag (`"{nodeId}.{epoch}.{stateVersion}"`): a store from the collaboration hub
 * moves it under a reader's feet exactly like another writer would, and a restore moves the generation, which fences the old room.
 */
beforeEach(function (): void {
    Hub::configure();
    $this->node = Hub::document(level: RoleRegistry::WRITER, actorId: 1);
    $this->id = (string) $this->node->getKey();
    $this->actingAs(AccessTestHelpers::user(1));
});

it('refuses a PUT after a hub store landed between the read and the write', function (): void {
    $tag = Docs::etag($this->id);

    Hub::call('store', Hub::storeBody($this->node, 'hub-state', Hub::tiptap('From the hub')), Hub::token($this->node))->assertOk();

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

    Hub::call('store', Hub::storeBody($this->node, 'old-room', epoch: $epoch), Hub::token($this->node, epoch: $epoch))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');
});

it('answers the hub of a document whose generation moved on a JSON replace with the epoch conflict', function (): void {
    Hub::call('store', Hub::storeBody($this->node, 'first-room', seed: true), Hub::token($this->node))->assertOk();
    $epoch = Hub::aggregate($this->node)->collab_epoch;

    Docs::put($this->id, Docs::doc('Replaced outside the room'))->assertOk();

    expect(Hub::aggregate($this->node)->collab_epoch)->toBe($epoch + 1);
    Hub::call('store', Hub::storeBody($this->node, 'stale-room', epoch: $epoch), Hub::token($this->node, epoch: $epoch))
        ->assertStatus(409)
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');
});
