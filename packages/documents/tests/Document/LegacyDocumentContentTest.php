<?php

declare(strict_types=1);

use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Documents\Support\TipTapRenderer;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * VLT-REMED P5.2 / R-01 (Blocker) — ВТОРОЙ, более тяжёлый удар барьера P0.1: он бил не
 * только по записи, но и по ЧТЕНИЮ. `NodeDocumentVersion::tipTapContent()` гидрировал
 * снапшот из БД тем же строгим `fromArray()`, и ЛЮБАЯ версия, сохранённая ДО P0.1 и
 * содержащая image/table/underline/textStyle/highlight, БРОСАЛА исключение при открытии,
 * превью и экспорте — вопреки собственному докблоку метода («decays to an empty doc rather
 * than throwing on read»). Один старый документ ронял весь UI.
 *
 * Контракт владельца (D27/D29): чтение ДЕГРАДИРУЕТ (strip неизвестного), запись остаётся
 * строгой. Данные в БД не мигрируются — decay работает на чтении.
 */
function legacyVersion(array $content): NodeDocumentVersion
{
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
        storage: $drive->storage(),
        name: 'Legacy '.bin2hex(random_bytes(4)),
        content: DocumentTestHelpers::content('placeholder'),
        parent: $drive->root(),
    ));

    $version = NodeDocumentVersion::query()->where('node_id', $node->getKey())->firstOrFail();

    // Перезаписываем снапшот «как он лежал до P0.1» — миграции данных нет, версия в БД
    // остаётся ровно такой, какой её записал старый код.
    $version->forceFill(['content' => $content])->save();

    return $version->fresh();
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

    // До фикса — InvalidTipTapContentException прямо здесь.
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

    // Опасное ЗНАЧЕНИЕ доезжает до рендерера (D29) — и оба выхода его гасят: HTML —
    // санитайзером (эшелон 2, D4), Markdown — allow-list схем.
    expect($renderer->toHtml($content))->not->toContain('javascript:')
        ->and($renderer->toMarkdown($content))->not->toContain('javascript:');
});
