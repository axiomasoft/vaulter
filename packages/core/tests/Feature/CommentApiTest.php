<?php

declare(strict_types=1);

use DocVault\Events\CommentAdded;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\TestUser;
use DocVault\Tests\Fixtures\ViewOnlyResolver;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 9]));
});

it('adds a comment and broadcasts it', function (): void {
    Event::fake([CommentAdded::class]);
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/comments', $document->id), ['body' => 'Looks good'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Looks good')
        ->assertJsonPath('data.author.type', TestUser::class);

    $this->assertDatabaseHas('dv_document_comments', ['document_id' => $document->id, 'body' => 'Looks good']);
    Event::assertDispatched(CommentAdded::class);
});

it('forbids commenting without the comment ability', function (): void {
    DocVault::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/comments', $document->id), ['body' => 'No'])
        ->assertForbidden();
});

it('lists and resolves comments', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $comment = DocumentComment::factory()->create(['document_id' => $document->id]);

    $this->getJson(sprintf('api/docvault/documents/%s/comments', $document->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson(sprintf('api/docvault/documents/%s/comments/%s/resolve', $document->id, $comment->id))
        ->assertOk()
        ->assertJsonPath('data.resolved_at', fn (?string $value): bool => $value !== null);

    expect(DocumentComment::query()->find($comment->id)?->isResolved())->toBeTrue();
});

it('deletes a comment with the manage ability', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $comment = DocumentComment::factory()->create(['document_id' => $document->id]);

    $this->deleteJson(sprintf('api/docvault/documents/%s/comments/%s', $document->id, $comment->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('dv_document_comments', ['id' => $comment->id]);
});
