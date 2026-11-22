<?php

declare(strict_types=1);

use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/** @return array{DriveHandle, NodeSnapshot, TestUser} */
function exportFixture(?string $role, string $type = 'vaulter/rich-text', ?array $content = null): array
{
    $handle = DocumentTestHelpers::drive();
    $document = Documents::in($handle)->create('Export', type: $type, content: $content ?? DocumentTestHelpers::content('export body')->toArray());
    if ($role !== null) {
        AccessTestHelpers::grant($document, $role, subjectId: 7);
    }

    return [$handle, $document, AccessTestHelpers::user(7)];
}

it('refuses a viewer without Download', function (): void {
    [$handle, $document, $user] = exportFixture(RoleRegistry::PREVIEWER);

    expect(fn () => Documents::in($handle->actingAs($user))->export($document->id, 'html'))->toThrow(PermissionDeniedException::class);
});

it('exports for a reader and hides the node from an actor with no grant', function (): void {
    [$handle, $document, $user] = exportFixture(RoleRegistry::READER);
    expect(Documents::in($handle->actingAs($user))->export($document->id, 'markdown')->bytes)->toContain('export body');

    [$handle, $document, $user] = exportFixture(null);
    expect(fn () => Documents::in($handle->actingAs($user))->export($document->id, 'html'))->toThrow(NodeNotFoundException::class);
});

it('answers node_not_found for a trashed document', function (): void {
    [$handle, $document, $user] = exportFixture(RoleRegistry::OWNER);
    $handle->nodes()->trash($document->id);

    expect(fn () => Documents::in($handle->actingAs($user))->export($document->id, 'html'))->toThrow(NodeNotFoundException::class);
});

it('answers document_type_unavailable when the type is not installed', function (): void {
    [$handle, $document, $user] = exportFixture(RoleRegistry::OWNER, 'acme/kanban', ['columns' => []]);
    TypesTestCase::installTypes([RichTextDocumentType::class, MarkdownDocumentType::class]);

    expect(fn () => Documents::in($handle->actingAs($user))->export($document->id, 'html'))->toThrow(DocumentTypeUnavailableException::class);
});

it('answers unknown_export_format with the allowed formats for a format the type lacks', function (): void {
    [$handle, $document, $user] = exportFixture(RoleRegistry::OWNER);
    $documents = Documents::in($handle->actingAs($user));

    try {
        $documents->export($document->id, 'docx');
        $this->fail('expected UnknownExportFormatException');
    } catch (UnknownExportFormatException $e) {
        expect($e->httpStatus())->toBe(422)
            ->and($e->problemCode())->toBe('unknown_export_format')
            ->and($e->getMessage())->toContain('[html, markdown]');
    }

    // A type no exporter lists has no formats at all, even for the built-in ones.
    [$handle, $opaque, $user] = exportFixture(RoleRegistry::OWNER, 'acme/opaque-note', ['id' => 'note', 'blocks' => []]);
    expect(fn () => Documents::in($handle->actingAs($user))->export($opaque->id, 'html'))->toThrow(UnknownExportFormatException::class);
});

it('answers 422 with the allowed list through HTTP and a stable code for a missing grant', function (): void {
    [, $document, $user] = exportFixture(RoleRegistry::OWNER);
    $this->actingAs($user);

    $this->getJson('api/vaulter/v1/documents/'.$document->id.'/export/docx')
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_export_format')
        ->assertJsonPath('meta.allowed', ['html', 'markdown']);
});
