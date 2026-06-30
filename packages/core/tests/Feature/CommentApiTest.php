<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Events\Documents\CommentAdded;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentComment;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Fixtures\ViewOnlyResolver;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 9]));
});

it('adds a comment and broadcasts it', function (): void {
    Event::fake([CommentAdded::class]);
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/comments', $document->id), ['body' => 'Looks good'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Looks good')
        ->assertJsonPath('data.author.type', TestUser::class);

    $this->assertDatabaseHas('v_document_comments', ['document_id' => $document->id, 'body' => 'Looks good']);
    Event::assertDispatched(CommentAdded::class);
});

it('forbids commenting without the comment ability', function (): void {
    Vaulter::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/comments', $document->id), ['body' => 'No'])
        ->assertForbidden();
});

it('lists and resolves comments', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $comment = DocumentComment::factory()->create(['document_id' => $document->id]);

    $this->getJson(sprintf('api/vaulter/documents/%s/comments', $document->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson(sprintf('api/vaulter/documents/%s/comments/%s/resolve', $document->id, $comment->id))
        ->assertOk()
        ->assertJsonPath('data.resolved_at', fn (?string $value): bool => $value !== null);

    expect(DocumentComment::query()->find($comment->id)?->isResolved())->toBeTrue();
});

it('deletes a comment with the manage ability', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $comment = DocumentComment::factory()->create(['document_id' => $document->id]);

    $this->deleteJson(sprintf('api/vaulter/documents/%s/comments/%s', $document->id, $comment->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('v_document_comments', ['id' => $comment->id]);
});
