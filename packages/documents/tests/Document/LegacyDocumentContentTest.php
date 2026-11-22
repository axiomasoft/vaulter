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
 * ВТОРОЙ, более тяжёлый удар строгой проверки контента: он бил не
 * только по записи, но и по ЧТЕНИЮ. `DocumentRevision::tipTapContent()` гидрировал
 * снапшот из БД тем же строгим `fromArray()`, и ЛЮБАЯ версия, сохранённая до неё и
 * содержащая image/table/underline/textStyle/highlight, БРОСАЛА исключение при открытии,
 * превью и экспорте — вопреки собственному докблоку метода («decays to an empty doc rather
 * than throwing on read»). Один старый документ ронял весь UI.
 *
 * Контракт владельца: чтение ДЕГРАДИРУЕТ (strip неизвестного), запись остаётся
 * строгой. Данные в БД не мигрируются — decay работает на чтении.
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

    // Перезаписываем снапшот «как он лежал в старом формате» прямо в БД (ревизии неизменяемы
    // через модель) — миграции данных нет, версия остаётся такой, какой её записал старый код.
    app(VaulterDatabase::class)->write(fn () => DocumentRevision::query()->whereKey($headId)->firstOrFail()->payload()->update(['content' => json_encode($content)]));

    return DocumentRevision::query()->whereKey($headId)->firstOrFail();
}

it('легаси-версия с неизвестными узлами ЧИТАЕТСЯ (decay), а не бросает, R-01', function (): void {
    $version = legacyVersion([
        'type' => 'doc',
        'content' => [
            ['type' => 'youtubeEmbed', 'attrs' => ['src' => 'https://youtu.be/x']],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'жив', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => 'red']]]],
            ]],
        ],
    ]);

    // До фикса — InvalidDocumentContentException прямо здесь.
    $content = $version->tipTapContent();

    expect($content)->toBeInstanceOf(TipTapContent::class)
        ->and($content->toArray())->toBe([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'жив']]],
            ],
        ]);

    // И она рендерится (превью/экспорт не падают) — то, ради чего decay и нужен.
    expect(app(TipTapRenderer::class)->toHtml($content))->toContain('жив');
});

it('легаси-версия с image/table (узлами собственного редактора) читается штатно, R-01', function (): void {
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

it('легаси-версия с javascript:-href читается и обезвреживается на рендере, R-07', function (): void {
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

    // Опасное ЗНАЧЕНИЕ доезжает до рендерера — и оба выхода его гасят: HTML —
    // санитайзером (эшелон 2), Markdown — allow-list схем.
    expect($renderer->toHtml($content))->not->toContain('javascript:')
        ->and($renderer->toMarkdown($content))->not->toContain('javascript:');
});
