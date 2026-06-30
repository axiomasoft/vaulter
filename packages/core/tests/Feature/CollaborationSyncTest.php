<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Events\Documents\DocumentContentSynced;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\DenyResolver;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 3]));
});

it('syncs collaborative content and broadcasts the change', function (): void {
    Event::fake([DocumentContentSynced::class]);
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create(['content' => ['type' => 'doc', 'content' => ['old']]]);

    $this->postJson(sprintf('api/vaulter/documents/%s/collab/sync', $document->id), [
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
    Vaulter::resolvePermissionsWith(new DenyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/collab/sync', $document->id), [
        'content' => ['type' => 'doc', 'content' => []],
    ])->assertForbidden();
});

it('rejects content without a doc root node', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/collab/sync', $document->id), [
        'content' => ['type' => 'paragraph'],
    ])->assertStatus(422);
});
