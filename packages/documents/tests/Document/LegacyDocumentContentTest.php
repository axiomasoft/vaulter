<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapRenderer;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * The SECOND, heavier impact of strict content validation: it hit not only
 * writes but also READS. `DocumentRevision::tipTapContent()` hydrated the
 * snapshot from the database with the same strict `fromArray()`, so ANY revision saved before it
 * that contained image/table/underline/textStyle/highlight THREW on open,
 * preview and export, contrary to the method's own docblock ("decays to an empty doc rather
 * than throwing on read"). One old document took the whole UI down.
 *
 * The owner's contract: reads DEGRADE (unknown content is stripped), writes stay
 * strict. Stored data is not migrated; decay happens on read.
 */
function legacyVersion(array $content): DocumentRevision
{
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Legacy '.bin2hex(random_bytes(4)),
        content: DocumentTestHelpers::content('placeholder'),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    $headId = Document::query()->where('node_id', $node->getKey())->value('current_revision_id');

    // Overwrite the snapshot "as it was stored in the old format" directly in the database (revisions are
    // immutable through the model): there is no data migration, the revision stays as the old code wrote it.
    app(VaulterDatabase::class)->write(fn () => DocumentRevision::query()->whereKey($headId)->firstOrFail()->payload()->update(['content' => json_encode($content)]));

    return DocumentRevision::query()->whereKey($headId)->firstOrFail();
}

it('a legacy revision with unknown nodes IS read (decay) instead of throwing', function (): void {
    $version = legacyVersion([
        'type' => 'doc',
        'content' => [
            ['type' => 'youtubeEmbed', 'attrs' => ['src' => 'https://youtu.be/x']],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'жив', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => 'red']]]],
            ]],
        ],
    ]);

    // Before the fix this threw InvalidDocumentContentException right here.
    $content = $version->tipTapContent();

    expect($content)->toBeInstanceOf(TipTapContent::class)
        ->and($content->toArray())->toBe([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'жив']]],
            ],
        ]);

    // And it renders (preview and export do not fail), which is what decay exists for.
    expect(app(TipTapRenderer::class)->toHtml($content))->toContain('жив');
});

it('a legacy revision with image/table (the editor own nodes) reads normally', function (): void {
    $version = legacyVersion([
        'type' => 'doc',
        'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png', 'alt' => 'pic']],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c']]]]],
                ]],
            ]],
        ],
    ]);

    $html = app(TipTapRenderer::class)->toHtml($version->tipTapContent());

    expect($html)->toContain('<img src="https://x.test/a.png"')
        ->toContain('<td><p>c</p></td>');
});

it('a legacy revision with a javascript: href is read and neutralized at render', function (): void {
    $version = legacyVersion([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]],
        ]],
    ]);

    $renderer = app(TipTapRenderer::class);
    $content = $version->tipTapContent();

    // The dangerous VALUE reaches the renderer and both outputs neutralize it: HTML through
    // the sanitizer (the second line of defence), Markdown through the scheme allow-list.
    expect($renderer->toHtml($content))->not->toContain('javascript:')
        ->and($renderer->toMarkdown($content))->not->toContain('javascript:');
});
