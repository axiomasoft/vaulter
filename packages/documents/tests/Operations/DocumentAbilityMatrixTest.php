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
use Vaulter\Drive\DriveHandle;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

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
    $url = 'api/vaulter/nodes/'.$document->id.$path;
    $body = match ($path) {
        '/content' => ['content' => DocumentTestHelpers::content('via http')->toArray()],
        '/comments' => ['body' => 'via http'],
        default => [],
    };

    $response = $this->json($verb, $url, $body)->assertStatus($status);
    if ($code !== null) {
        $response->assertJsonPath('code', $code);
    }
})->with([
    'previewer reads content' => [RoleRegistry::PREVIEWER, 'GET', '/content', 200, null],
    'previewer cannot export' => [RoleRegistry::PREVIEWER, 'GET', '/export?format=html', 403, 'permission_denied'],
    'previewer cannot autosave' => [RoleRegistry::PREVIEWER, 'PATCH', '/content', 403, 'permission_denied'],
    'reader exports' => [RoleRegistry::READER, 'GET', '/export?format=html', 200, null],
    'reader cannot comment' => [RoleRegistry::READER, 'POST', '/comments', 403, 'permission_denied'],
    'commenter comments' => [RoleRegistry::COMMENTER, 'POST', '/comments', 201, null],
    'commenter gets a token' => [RoleRegistry::COMMENTER, 'POST', '/collab/token', 200, null],
    'writer autosaves' => [RoleRegistry::WRITER, 'PATCH', '/content', 200, null],
    'no grant hides content' => [null, 'GET', '/content', 404, 'node_not_found'],
    'no grant hides history' => [null, 'GET', '/document-versions', 404, 'node_not_found'],
    'no grant hides comments' => [null, 'POST', '/comments', 404, 'node_not_found'],
    'no grant hides the token' => [null, 'POST', '/collab/token', 404, 'node_not_found'],
]);

it('hides a trashed document from the HTTP routes with node_not_found', function (): void {
    [$handle, $document, , $user] = matrixFixture(RoleRegistry::OWNER);
    $handle->nodes()->trash($document->id);
    $this->actingAs($user);

    foreach (['/content', '/document-versions', '/export?format=html'] as $path) {
        $this->getJson('api/vaulter/nodes/'.$document->id.$path)->assertNotFound()->assertJsonPath('code', 'node_not_found');
    }
});
