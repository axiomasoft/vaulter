<?php

declare(strict_types=1);

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Support\TipTapRenderer;
use Vaulter\Exceptions\Documents\InvalidTipTapContentException;
use Vaulter\ValueObjects\Documents\TipTapContent;

function sampleDocument(): TipTapContent
{
    return TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' & '],
                ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]]]],
            ]],
        ],
    ]);
}

/**
 * Все цели ссылок/картинок из markdown-вывода: и `](...)`, и угловая форма `](<...>)`.
 * Свойство, а не подстрока: тест доказывает, что КАЖДАЯ реально выведенная цель имеет
 * разрешённую схему — ассерт «в выводе нет javascript:alert(1)» подгоняется под конкретный
 * вектор и пропускает соседний (R-04).
 *
 * @return list<string>
 */
function markdownLinkTargets(string $markdown): array
{
    preg_match_all('/!?\]\(\s*(?:<([^>]*)>|([^)\s]*))/', $markdown, $matches, PREG_SET_ORDER);

    $targets = [];

    foreach ($matches as $match) {
        $target = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

        if ($target !== '') {
            $targets[] = urldecode($target);
        }
    }

    return $targets;
}

it('renders TipTap content to HTML with escaping', function (): void {
    $html = app(TipTapRenderer::class)->toHtml(sampleDocument());

    expect($html)->toContain('<h2>Title</h2>')
        ->toContain('<strong>world</strong>')
        ->toContain('<a href="https://x.test">link</a>')
        ->toContain('&amp;')
        ->toContain('<li><p>one</p></li>');
});

it('renders TipTap content to Markdown', function (): void {
    $markdown = app(TipTapRenderer::class)->toMarkdown(sampleDocument());

    expect($markdown)->toContain('## Title')
        ->toContain('**world**')
        ->toContain('[link](https://x.test)')
        ->toContain('- one')
        ->toContain('- two');
});

it('рендерит новые узлы контракта D27 (image, table) и марку underline, R-01', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png', 'alt' => 'pic']],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'u', 'marks' => [['type' => 'underline']]],
            ]],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'h']]]]],
                ]],
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c']]]]],
                ]],
            ]],
        ],
    ]);

    $renderer = app(TipTapRenderer::class);

    // Форма тегов — уже ПОСЛЕ санитайзера (он нормализует `<img>` в `<img />` и достраивает
    // `<tbody>`): проверяем то, что реально уходит потребителю.
    expect($renderer->toHtml($doc))
        ->toContain('<img src="https://x.test/a.png" alt="pic" />')
        ->toContain('<u>u</u>')
        ->toContain('<th><p>h</p></th>')
        ->toContain('<td><p>c</p></td>');

    expect($renderer->toMarkdown($doc))
        ->toContain('![pic](https://x.test/a.png)')
        ->toContain('| h |')
        ->toContain('| c |');
});

// ─── F-P1.11-01 — stored XSS via javascript: href (store time) ──────────────

it('rejects a javascript: link href at store time, F-P1.11-01', function (): void {
    TipTapContent::fromArray([
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
})->throws(InvalidTipTapContentException::class);

it('rejects obfuscated javascript:/data: schemes at store time, F-P1.11-01', function (string $href): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'x',
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ]);
})->with([
    'uppercase scheme' => ['JAVASCRIPT:alert(1)'],
    'embedded tab' => ["java\tscript:alert(1)"],
    'BiDi override' => ["\u{202E}javascript:alert(1)"],
    'data URI' => ['data:text/html;base64,PHNjcmlwdD4='],
])->throws(InvalidTipTapContentException::class);

it('rejects an unknown node type at store time, F-P1.11-02', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'script', 'content' => []]],
    ]);
})->throws(InvalidTipTapContentException::class);

it('rejects an unknown attribute at store time, F-P1.11-02', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['style' => 'background:url(javascript:alert(1))'],
            'content' => [],
        ]],
    ]);
})->throws(InvalidTipTapContentException::class);

// ─── R-07 — второй эшелон D4 (render-time HTML-санитайзер) ──────────────────

/*
 * До P5.2 эшелон 2 был НЕДОСТИЖИМ: единственный вход в TipTapContent — строгий fromArray(),
 * который отвергал опасный контент раньше, чем санитайзер успевал его увидеть, — а «тесты
 * эшелона 2» на деле пиннили store-time. С decay-on-read (D29) легаси-снапшот с
 * javascript:-href РЕАЛЬНО доходит до рендерера, и его обезвреживает symfony/html-sanitizer.
 */
function legacyHostileSnapshot(): array
{
    return [
        'type' => 'doc',
        'content' => [
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]]],
            ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']],
        ],
    ];
}

it('легаси-снапшот с javascript:-href доходит до рендерера и обезвреживается санитайзером, R-07', function (): void {
    $html = app(TipTapRenderer::class)->toHtml(TipTapContent::decayFrom(legacyHostileSnapshot()));

    // Санитайзер снял опасный href/src; текст ссылки при этом сохранён.
    expect($html)->not->toContain('javascript:')
        ->and($html)->toContain('click me');
});

it('эшелон 2 ДОСТИЖИМ: с pass-through-санитайзером тот же снапшот течёт, R-07', function (): void {
    // Доказательство красноты (D19-форма): подменяем санитайзер прозрачным стабом. Если бы
    // рендерер сам резал href раньше санитайзера, эшелон был бы фикцией и этот тест остался
    // бы зелёным — он обязан пасть, показав, что вход у эшелона реальный.
    app()->instance(HtmlSanitizerInterface::class, new class implements HtmlSanitizerInterface
    {
        public function sanitize(string $input): string
        {
            return $input;
        }

        public function sanitizeFor(string $element, string $input): string
        {
            return $input;
        }
    });

    $html = app(TipTapRenderer::class)->toHtml(TipTapContent::decayFrom(legacyHostileSnapshot()));

    expect($html)->toContain('javascript:alert(1)');
});

// ─── R-04 / R-10 / F-P1.11-04 — markdown-путь ──────────────────────────────

it('markdown-экспорт: схема КАЖДОЙ выведенной цели ссылки разрешена, R-04', function (string $label, string $href): void {
    // Легаси-снапшот (decay, не fromArray): store-time href мог быть легальным, а вредонос
    // — в ЛЕЙБЛЕ; либо href легальной схемы несёт ')' и закрывает цель раньше времени.
    $markdown = app(TipTapRenderer::class)->toMarkdown(TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $label,
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ]));

    $schemes = array_map(
        static fn (string $target): string => mb_strtolower((string) parse_url($target, PHP_URL_SCHEME)),
        markdownLinkTargets($markdown),
    );

    // Одна проверка, которая исполняется ВСЕГДА (в т.ч. когда целей не осталось вовсе):
    // множество схем выведенных целей не имеет ничего вне allow-list.
    expect(array_values(array_diff($schemes, ['http', 'https', 'mailto'])))
        ->toBe([], "опасная схема в целях markdown-ссылок; вывод: [{$markdown}]");
})->with([
    // Вектор A из реестра P5.1: инъекция через label.
    'label закрывает ссылку' => ['click](javascript:alert(1))', 'https://ok.example'],
    // Вектор B из реестра P5.1: ')' внутри разрешённой https-схемы.
    'href со скобкой' => ['safe', 'https://a.example/) [pwn](javascript:alert(1)'],
    'javascript' => ['x', 'javascript:alert(1)'],
    'data' => ['x', 'data:text/html,<script>alert(1)</script>'],
    'vbscript' => ['x', 'vbscript:msgbox(1)'],
    'file' => ['x', 'file:///etc/passwd'],
    'обфускация табом' => ['x', "java\tscript:alert(1)"],
    'обфускация регистром' => ['x', 'JaVaScRiPt:alert(1)'],
    'обфускация процентами' => ['x', '%6a%61%76%61script:alert(1)'],
    'изображение в label' => ['![x](javascript:alert(1))', 'https://ok.example'],
]);

it('markdown-экспорт: опасный src картинки не выводится, R-04', function (): void {
    $markdown = app(TipTapRenderer::class)->toMarkdown(TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'x']]],
    ]));

    expect(markdownLinkTargets($markdown))->toBe([]);
});

it('escapes raw text in markdown export, F-P1.11-04', function (): void {
    // Литеральный "<script>" — легитимный текст (не инъекция на уровне TipTap-JSON), и
    // store-time его не отвергает. Экспорт обязан отдать его инертным.
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    expect($markdown)
        ->not->toContain('<script>alert(1)</script>')
        // Markdown-экранирование, а не HTML: `\<script\>`, НЕ `&lt;script&gt;` (R-10).
        ->toContain('\<script\>')
        ->not->toContain('&lt;');
});

it('markdown-экспорт НЕ печатает HTML-сущности: & в URL и < в тексте, R-10', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'a < b & c'],
                ['type' => 'text', 'text' => 'q', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test/?a=1&b=2']]]],
            ],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    // URL доезжает целым (было: https://x.test/?a=1&amp;b=2 — сломанная ссылка).
    expect(markdownLinkTargets($markdown))->toBe(['https://x.test/?a=1&b=2'])
        // Текст не показывает литеральные сущности (было: `a &lt; b &amp; c`).
        ->and($markdown)->not->toContain('&lt;')
        ->and($markdown)->not->toContain('&amp;');
});

it('keeps a valid link and drops disallowed link schemes in markdown export, F-P1.11-04', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]],
            ]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    expect($markdown)->toContain('[click me](https://x.test)');
});
