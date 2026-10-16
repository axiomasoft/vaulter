<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Snapshots\DocumentRevisionSnapshot;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Operations\CommentOperations;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

/**
 * PLAN2.P4.8: every method of the Documents API against every built-in role, plus a
 * trashed node, a node of another drive and a node of another tenant. Expected results
 * follow the role capabilities (RoleRegistry): View reads, Edit writes, Download
 * exports, Comment comments; a node the actor cannot see is node_not_found, never
 * permission_denied. The HTTP block repeats representative cells through the routes.
 *
 * Roles: previewer {view}; reader {view, download}; commenter {reader + comment};
 * writer/owner {… + edit}. A previewer reads content in the app but cannot export it.
 */

/** @return array{DriveHandle, NodeSnapshot, DocumentRevisionSnapshot, TestUser} */
function matrixFixture(?string $role, int $user = 7): array
{
    $handle = DocumentTestHelpers::drive();
    $document = Documents::in($handle)->create('Matrix', content: DocumentTestHelpers::content('matrix body')->toArray());
    $first = Documents::in($handle)->revisions($document->id)->items[0];
    if ($role !== null) {
        AccessTestHelpers::grant($document, $role, subjectId: $user);
    }

    return [$handle, $document, $first, AccessTestHelpers::user($user)];
}

/** @return array<string, Closure(DocumentOperations, CommentOperations, NodeSnapshot, DocumentRevisionSnapshot): mixed> */
function matrixMethods(): array
{
    return [
        'content' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->content($doc->id),
        'revisions' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->revisions($doc->id),
        'update' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->update($doc->id, DocumentTestHelpers::content('changed')->toArray()),
        'snapshot' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->snapshot($doc->id, 'named'),
        'restore' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->restore($doc->id, $first),
        'setRetention' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->setRetention($doc->id, $first, true),
        'export' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->export($doc->id, 'html'),
        'collaborationSession' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $ops->collaborationSession($doc->id),
        'comments.list' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $comments->list($doc->id),
        'comments.add' => static fn (DocumentOperations $ops, CommentOperations $comments, NodeSnapshot $doc, DocumentRevisionSnapshot $first): mixed => $comments->add($doc->id, new AddCommentInput('matrix comment')),
    ];
}

/**
 * ok = returns a result; ro/rw = collaboration session mode; denied =
 * permission_denied; hidden = node_not_found.
 *
 * @return array<string, array{?string, string, string}>
 */
function matrixCases(): array
{
    $viewer = ['content' => 'ok', 'revisions' => 'ok', 'update' => 'denied', 'snapshot' => 'denied', 'restore' => 'denied', 'setRetention' => 'denied', 'export' => 'denied', 'collaborationSession' => 'ro', 'comments.list' => 'ok', 'comments.add' => 'denied'];
    $reader = ['export' => 'ok'] + $viewer;
    $commenter = ['comments.add' => 'ok'] + $reader;
    $editor = ['update' => 'ok', 'snapshot' => 'ok', 'restore' => 'ok', 'setRetention' => 'ok', 'collaborationSession' => 'rw'] + $commenter;
    $table = [
        'none' => array_fill_keys(array_keys($viewer), 'hidden'),
        RoleRegistry::PREVIEWER => $viewer,
        RoleRegistry::READER => $reader,
        RoleRegistry::COMMENTER => $commenter,
        RoleRegistry::WRITER => $editor,
        RoleRegistry::OWNER => $editor,
    ];
    $cases = [];
    foreach ($table as $role => $methods) {
        foreach ($methods as $method => $expected) {
            $cases[$role.' '.$method] = [$role === 'none' ? null : $role, $method, $expected];
        }
    }

    return $cases;
}

function matrixCall(DriveHandle $handle, string $method, NodeSnapshot $document, DocumentRevisionSnapshot $first): mixed
{
    return matrixMethods()[$method](Documents::in($handle), Documents::comments($handle), $document, $first);
}

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('authorizes every documents method by role', function (?string $role, string $method, string $expected): void {
    [$handle, $document, $first, $user] = matrixFixture($role);
    $acting = $handle->actingAs($user);
    $call = static fn (): mixed => matrixCall($acting, $method, $document, $first);

    match ($expected) {
        'hidden' => expect($call)->toThrow(NodeNotFoundException::class),
        'denied' => expect($call)->toThrow(PermissionDeniedException::class),
        'ro', 'rw' => expect($call()->readOnly)->toBe($expected === 'ro'),
        default => expect($call())->not->toBeNull(),
    };
})->with(matrixCases());

it('answers node_not_found for a trashed document on every method', function (string $method): void {
    [$handle, $document, $first, $user] = matrixFixture(RoleRegistry::OWNER);
    $handle->nodes()->trash($document->id);

    expect(fn (): mixed => matrixCall($handle->actingAs($user), $method, $document, $first))->toThrow(NodeNotFoundException::class);
})->with(array_keys(matrixMethods()));

it('answers node_not_found for a document of another drive on every method', function (string $method): void {
    [, $document, $first, $user] = matrixFixture(RoleRegistry::OWNER);
    $other = DocumentTestHelpers::drive();

    expect(fn (): mixed => matrixCall($other->actingAs($user), $method, $document, $first))->toThrow(NodeNotFoundException::class);
})->with(array_keys(matrixMethods()));

it('answers node_not_found for a document of another tenant on every method', function (string $method): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    [$handle, $document, $first, $user] = matrixFixture(RoleRegistry::OWNER);
    $acting = $handle->actingAs($user);

    $resolver->tenant = 'B';
    expect(fn (): mixed => matrixCall($acting, $method, $document, $first))->toThrow(NodeNotFoundException::class);

    // Positive control: the same call succeeds inside the document's own tenant.
    $resolver->tenant = 'A';
    expect(matrixCall($acting, $method, $document, $first))->not->toBeNull();
})->with(array_keys(matrixMethods()));

/*
 * HTTP parity: the routes give the same answers as the operations.
 */
it('returns the same codes through the HTTP routes', function (?string $role, string $verb, string $path, int $status, ?string $code): void {
    [, $document, , $user] = matrixFixture($role);
    $this->actingAs($user);
    $url = Docs::url(str_replace('{id}', (string) $document->id, $path));
    $body = match (true) {
        str_ends_with($path, '/content') && $verb === 'PUT' => ['content' => DocumentTestHelpers::content('via http')->toArray()],
        str_ends_with($path, '/comments') => ['body' => 'via http'],
        default => [],
    };
    // A caller who may write reads the tag first; the others are refused before any tag is compared.
    $headers = $verb === 'PUT' ? ($role === RoleRegistry::WRITER ? Docs::ifMatch((string) $document->id) : ['If-Match' => '"any"']) : [];

    $response = $this->json($verb, $url, $body, $headers)->assertStatus($status);
    if ($code !== null) {
        $response->assertJsonPath('code', $code);
    }
})->with([
    'previewer reads content' => [RoleRegistry::PREVIEWER, 'GET', 'documents/{id}/content', 200, null],
    'previewer cannot export' => [RoleRegistry::PREVIEWER, 'GET', 'documents/{id}/export/html', 403, 'permission_denied'],
    'previewer cannot autosave' => [RoleRegistry::PREVIEWER, 'PUT', 'documents/{id}/content', 403, 'permission_denied'],
    'reader exports' => [RoleRegistry::READER, 'GET', 'documents/{id}/export/html', 200, null],
    'reader cannot comment' => [RoleRegistry::READER, 'POST', 'nodes/{id}/comments', 403, 'permission_denied'],
    'commenter comments' => [RoleRegistry::COMMENTER, 'POST', 'nodes/{id}/comments', 201, null],
    'commenter gets a session' => [RoleRegistry::COMMENTER, 'POST', 'documents/{id}/collaboration/session', 201, null],
    'writer autosaves' => [RoleRegistry::WRITER, 'PUT', 'documents/{id}/content', 200, null],
    'no grant hides content' => [null, 'GET', 'documents/{id}/content', 404, 'node_not_found'],
    'no grant hides the write' => [null, 'PUT', 'documents/{id}/content', 404, 'node_not_found'],
    'no grant hides history' => [null, 'GET', 'documents/{id}/revisions', 404, 'node_not_found'],
    'no grant hides comments' => [null, 'POST', 'nodes/{id}/comments', 404, 'node_not_found'],
    'no grant hides the session' => [null, 'POST', 'documents/{id}/collaboration/session', 404, 'node_not_found'],
]);

it('hides a trashed document from the HTTP routes with node_not_found', function (): void {
    [$handle, $document, , $user] = matrixFixture(RoleRegistry::OWNER);
    $handle->nodes()->trash($document->id);
    $this->actingAs($user);

    foreach (['content', 'revisions', 'export/html'] as $path) {
        $this->getJson(Docs::url('documents/'.$document->id.'/'.$path))->assertNotFound()->assertJsonPath('code', 'node_not_found');
    }
});
