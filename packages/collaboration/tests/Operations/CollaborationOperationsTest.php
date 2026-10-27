<?php

declare(strict_types=1);

use Vaulter\Collaboration\Operations\CollaborationOperations;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers;
use Vaulter\Collaboration\Tests\Support\AcmeKanbanV2Type;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Snapshots\NodeSnapshot;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\MissingActorException;

/**
 * CollaborationOperations::session(): the collaboration use case of a drive handle (`$handle->extension(...)`), under the real
 * database grants. The documents it serves are created through the documents API.
 */
beforeEach(function (): void {
    HubTestHelpers::configure();
});

/** @return array{DriveHandle, NodeSnapshot} a drive handle (no user) and a document in it */
function sessionFixture(): array
{
    $handle = DocumentTestHelpers::drive();
    $node = Documents::in($handle)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());

    return [$handle, $node];
}

function sessions(DriveHandle $handle): CollaborationOperations
{
    return $handle->extension(CollaborationOperations::class);
}

it('needs a user for a collaboration session and maps Edit to read-write', function (): void {
    [$handle, $node] = sessionFixture();
    AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 5);

    expect(fn () => sessions($handle)->session($node->id))->toThrow(MissingActorException::class)
        ->and(sessions($handle->actingAs(AccessTestHelpers::user(5)))->session($node->id)->mode)->toBe(CollaborationMode::Write);
});

it('binds a collaboration session to the document generation and schema', function (): void {
    [$handle, $node] = sessionFixture();
    AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 5);
    $acting = $handle->actingAs(AccessTestHelpers::user(5));
    $documents = Documents::in($acting);
    $documents->restore($node->id, $documents->revisions($node->id)->items[0]->id);

    $session = sessions($acting)->session($node->id);

    expect($session->generation)->toBe(1)
        ->and($session->schemaVersion)->toBe(1)
        ->and($session->protocol)->toBe('yjs')
        ->and($session->nodeId)->toBe($node->id)
        ->and($session->options['drive_id'])->toBe($node->driveId)
        ->and($session->options['room'])->toBe('vaulter.documents.'.$node->driveId.'.'.$node->id.'.1')
        ->and($session->mode)->toBe(CollaborationMode::Write);
});

it('answers collaboration_unavailable when the type cannot collaborate', function (): void {
    DocumentTestHelpers::installTypes([RichTextDocumentType::class, MarkdownDocumentType::class, AcmeKanbanV2Type::class]);
    $board = HubTestHelpers::document(level: RoleRegistry::WRITER, actorId: 5, content: OpaqueContent::fromArray(['columns' => []]), type: 'acme/kanban', schemaVersion: 2);
    $handle = Vaulter::drive($board->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(5));

    expect(fn () => sessions($handle)->session($board->getKey()))->toThrow(CollaborationUnavailableException::class, 'unsupported_type');
});

it('gives an editor a read-only session when the room could not write', function (Closure $arrange): void {
    [$node, $clientSchemaVersion] = $arrange();
    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(5));

    expect(sessions($handle)->session($node->getKey(), $clientSchemaVersion)->mode)->toBe(CollaborationMode::Read);
})->with([
    'type not installed' => [static fn (): array => [HubTestHelpers::document(level: RoleRegistry::WRITER, actorId: 5, content: OpaqueContent::fromArray(['cells' => []]), type: 'acme/gone'), null]],
    'client speaks another schema' => [static fn (): array => [HubTestHelpers::document(level: RoleRegistry::WRITER, actorId: 5), 2]],
    'archived drive' => [static function (): array {
        $node = HubTestHelpers::document(level: RoleRegistry::WRITER, actorId: 5);
        app(VaulterDatabase::class)->write(fn () => $node->storage()->update(['status' => 'archived']));

        return [$node, null];
    }],
]);

// What the role of the caller makes of the session: a viewer joins read-only, an editor read-write, a caller without a grant sees nothing.
it('maps every role to its session mode and hides the document from a caller without a grant', function (?string $role, string $expected): void {
    $node = HubTestHelpers::document(level: $role, actorId: 5);
    $handle = Vaulter::drive($node->storage()->firstOrFail())->actingAs(AccessTestHelpers::user(5));
    $call = static fn (): mixed => sessions($handle)->session($node->getKey());

    match ($expected) {
        'hidden' => expect($call)->toThrow(NodeNotFoundException::class),
        'denied' => expect($call)->toThrow(PermissionDeniedException::class),
        default => expect($call()->mode())->toBe($expected),
    };
})->with([
    'previewer' => [RoleRegistry::PREVIEWER, 'ro'],
    'reader' => [RoleRegistry::READER, 'ro'],
    'commenter' => [RoleRegistry::COMMENTER, 'ro'],
    'writer' => [RoleRegistry::WRITER, 'rw'],
    'no grant' => [null, 'hidden'],
]);

it('hides a trashed document and a document of another drive from the session', function (): void {
    [$handle, $node] = sessionFixture();
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 5);
    $acting = $handle->actingAs(AccessTestHelpers::user(5));

    expect(fn () => sessions(DocumentTestHelpers::drive()->actingAs(AccessTestHelpers::user(5)))->session($node->id))->toThrow(NodeNotFoundException::class);

    $acting->nodes()->trash($node->id);

    expect(fn () => sessions($acting)->session($node->id))->toThrow(NodeNotFoundException::class);
});
