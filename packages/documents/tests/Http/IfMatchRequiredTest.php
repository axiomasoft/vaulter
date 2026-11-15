<?php

declare(strict_types=1);

use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments as Granted;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * Every route that changes an existing document or comment — the document's content, a restore, the resolution and the
 * deletion of a comment — refuses to run blind: without If-Match, or with `*` (which matches anything), it
 * answers 428 and changes nothing; a caller who may not write is answered 404/403 before any precondition.
 */
beforeEach(function (): void {
    Granted::configure();
    config()->set('vaulter-documents.comments.enabled', true);
});

/**
 * The request of one IM route against a fresh document and comment of a writer (user 1).
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: string} method, path, body, node id, comment id
 */
function imDocumentRequest(string $route): array
{
    $node = Granted::document(level: RoleRegistry::WRITER, actorId: 1);
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 2);
    $id = (string) $node->getKey();
    test()->actingAs(AccessTestHelpers::user(1));
    $revision = test()->postJson(Docs::url('documents/'.$id.'/revisions'), ['label' => 'v1'])->assertCreated()->json('data.id');
    $comment = test()->postJson(Docs::url('nodes/'.$id.'/comments'), ['body' => 'thread'])->assertCreated()->json('data.id');

    return match ($route) {
        'documents.content.update' => ['PUT', 'documents/'.$id.'/content', ['content' => Docs::doc('x')], $id, (string) $comment],
        'documents.revisions.restore' => ['POST', 'documents/'.$id.'/revisions/'.$revision.'/restore', [], $id, (string) $comment],
        'comments.resolve' => ['POST', 'nodes/'.$id.'/comments/'.$comment.'/resolve', [], $id, (string) $comment],
        'comments.destroy' => ['DELETE', 'nodes/'.$id.'/comments/'.$comment, [], $id, (string) $comment],
    };
}

dataset('im routes', [
    'documents.content.update', 'documents.revisions.restore', 'comments.resolve', 'comments.destroy',
]);

it('answers 428 without If-Match and leaves the document and the comment as they were', function (string $route): void {
    [$method, $path, $body, $node, $comment] = imDocumentRequest($route);
    $state = Granted::aggregate(Node::query()->findOrFail($node))->state_version;

    $this->json($method, Docs::url($path), $body)
        ->assertStatus(428)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.header', 'If-Match');

    $row = NodeComment::query()->find($comment);
    expect(Granted::aggregate(Node::query()->findOrFail($node))->state_version)->toBe($state)
        ->and($row?->version)->toBe(0)
        ->and($row?->resolved_at)->toBeNull();
})->with('im routes');

it('answers 428 to If-Match: * because it would match any representation', function (string $route): void {
    [$method, $path, $body] = imDocumentRequest($route);

    $this->json($method, Docs::url($path), $body, ['If-Match' => '*'])
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.reason', 'wildcard_not_allowed');
})->with('im routes');

it('answers a caller who cannot see the node 404, not 428', function (string $route): void {
    [$method, $path, $body] = imDocumentRequest($route);
    $this->actingAs(AccessTestHelpers::user(9));

    $this->json($method, Docs::url($path), $body)->assertNotFound();
})->with('im routes');

it('answers a caller who may view but not write 403 before any precondition', function (string $route): void {
    [$method, $path, $body] = imDocumentRequest($route);
    $this->actingAs(AccessTestHelpers::user(2));

    $this->json($method, Docs::url($path), $body)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->json($method, Docs::url($path), $body, ['If-Match' => '"stale"'])->assertForbidden();
})->with('im routes');
