<?php

declare(strict_types=1);

use DocVault\Events\DocumentContentSynced;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\DenyResolver;
use DocVault\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 3]));
});

it('syncs collaborative content and broadcasts the change', function (): void {
    Event::fake([DocumentContentSynced::class]);
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['content' => ['type' => 'doc', 'content' => ['old']]]);

    $this->postJson(sprintf('api/docvault/documents/%s/collab/sync', $document->id), [
        'content' => ['type' => 'doc', 'content' => ['new']],
    ])
        ->assertOk()
        ->assertJsonPath('data.content.content.0', 'new');

    Event::assertDispatched(
        DocumentContentSynced::class,
        fn (DocumentContentSynced $event): bool => (string) $event->document->id === (string) $document->id,
    );
});

it('forbids syncing when the resolver denies edit', function (): void {
    DocVault::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/collab/sync', $document->id), [
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertForbidden();
});

it('rejects content without a doc root node', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/collab/sync', $document->id), [
        'content' => ['type' => 'paragraph'],
    ])->assertStatus(422);
});
