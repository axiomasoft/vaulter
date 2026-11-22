<?php

declare(strict_types=1);

use Vaulter\Documents\Tests\Fixtures\Types\OpaqueNoteType;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/*
 * The export path is kind-neutral: an opaque document is never rendered as a blank
 * rich-text document. No built-in exporter lists its type, so the format is refused
 * with an empty allowed list before any content is read.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    // The host lists the opaque note type in vaulter-documents.types.
    $types = new DocumentTypeRegistry(app());
    foreach ([RichTextDocumentType::class, MarkdownDocumentType::class, OpaqueNoteType::class] as $type) {
        $types->register($type);
    }
    $types->freeze();
    app()->instance(DocumentTypeRegistry::class, $types);
});

function opaqueNoteStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

it('refuses to export a non-rich-text opaque node instead of rendering it blank', function (string $format): void {
    $drive = opaqueNoteStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Scratchpad',
        'type' => OpaqueNoteType::KEY,
        'content' => ['id' => 'note1', 'blocks' => []],
    ])->assertCreated()->json('data.id');

    $this->getJson(Docs::url('documents/'.$nodeId.'/export/'.$format))
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_export_format')
        ->assertJsonPath('meta.allowed', []);
})->with(['html', 'markdown']);
