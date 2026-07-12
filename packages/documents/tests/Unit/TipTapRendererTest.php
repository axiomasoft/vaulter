<?php

declare(strict_types=1);

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Exceptions\InvalidTipTapContentException;
use Vaulter\Documents\Support\TipTapRenderer;
use Vaulter\Documents\ValueObjects\TipTapContent;

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

// ─── P5.2 — code-span и таблица: два пути в обход escapeMarkdown ─────────────

/*
 * Ветка `code` — единственная, которая НЕ проходит через escapeMarkdown (внутри code-span
 * бэкслеши литеральны), и потому выпускала текст СЫРЫМ. Спан инертен ровно до тех пор, пока
 * он смыкается, а пустая строка закрывает не спан, а весь АБЗАЦ: всё, что после неё, падает
 * в .md как markup верхнего уровня. Store-time такой payload принимает — это легитимный
 * ТЕКСТ, а не инъекция на уровне TipTap-JSON. Ровно класс F-P1.11-04 / R-04, который
 * соседняя ветка text экранированием `<`/`>` и закрывает.
 */
it('markdown-экспорт: code-марка не выпускает сырой HTML через разрыв абзаца, R-04', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => "a\n\n<script>alert(1)</script>",
                'marks' => [['type' => 'code']],
            ]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $span = trim($markdown);

    // До фикса: "`a\n\n<script>alert(1)</script>`" — пустая строка рвала абзац, спан не
    // смыкался, и <script> выходил в .md ЖИВЫМ блоком сырого HTML.
    expect($markdown)->not->toContain("\n\n")
        // Ровно ОДИН сомкнутый code-span на весь вывод…
        ->and(substr_count($span, '`'))->toBe(2)
        ->and($span)->toStartWith('`')
        ->and($span)->toEndWith('`')
        // …и payload целиком внутри него — а значит инертен.
        ->and($span)->toContain('<script>alert(1)</script>');
});

/*
 * Пайп экранировался ДВАЖДЫ: escapeMarkdown уже отдаёт `\|`, а ячейка сверху делала
 * str_replace('|', '\|') → `\\|`. GFM читает `\\` как экранированный обратный слэш, а
 * следующий `|` — как ЖИВОЙ разделитель: ячейка разваливается на лишнюю колонку и сдвигает
 * все следующие. Та самая column-injection, которую комментарий обещал предотвращать.
 */
it('markdown-экспорт: `|` в ячейке не добавляет колонку, R-04', function (): void {
    $cell = static fn (string $type, string $text, bool $code = false): array => [
        'type' => $type,
        'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => $text] + ($code ? ['marks' => [['type' => 'code']]] : []),
        ]]],
    ];

    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'table',
            'content' => [
                ['type' => 'tableRow', 'content' => [$cell('tableHeader', 'h1'), $cell('tableHeader', 'h2')]],
                ['type' => 'tableRow', 'content' => [$cell('tableCell', 'a|b'), $cell('tableCell', 'c')]],
                // code-span — вторая дорога в ячейку мимо escapeMarkdown.
                ['type' => 'tableRow', 'content' => [$cell('tableCell', 'x|y', code: true), $cell('tableCell', 'z')]],
            ],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);

    // Двойного экранирования нет ни на одном пути…
    expect($markdown)->not->toContain('\\\\|')
        ->and($markdown)->toContain('| a\|b | c |')
        ->and($markdown)->toContain('| `x\|y` | z |');

    // …и каждая строка таблицы несёт РОВНО две колонки (заголовок задаёт ширину).
    foreach (explode("\n", trim($markdown)) as $line) {
        expect(substr_count($line, '|') - substr_count($line, '\|'))->toBe(3, "строка таблицы сломана: [{$line}]");
    }
});

/*
 * RV-01 — ревью P5.2 (тот же класс F-P1.11-01/-04, соседний путь) — markdown-ветка codeBlock печатала
 * `'```'.attrs.language."\n".plainText($children)."\n```"`, СЫРЫМИ обе половины. Тело
 * кодоблока, содержащее собственный fence, закрывает его раньше времени, и всё, что дальше,
 * падает в .md как markup верхнего уровня — ЖИВОЙ сырой HTML. Store-time такой контент
 * принимает: это легитимный ТЕКСТ. Ровно то, что P5.2 закрыл для inline-марки code
 * (markdownCodeSpan), но узел-брат codeBlock остался сырым.
 *
 * Свойство, а не подстрока: открывающий и закрывающий fence одинаковы и длиннее любой
 * последовательности бэктиков в теле (CommonMark) — тогда тело инертно ЦЕЛИКОМ, чем бы оно
 * ни было.
 */
it('markdown-экспорт: тело codeBlock не закрывает свой fence и не выпускает сырой HTML, RV-01', function (): void {
    $body = "innocent\n```\n<script>alert(1)</script>\n```";

    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'codeBlock',
            'content' => [['type' => 'text', 'text' => $body]],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $lines = explode("\n", trim($markdown));

    // Fence — длиннее самого длинного прогона бэктиков в теле (3) → минимум 4.
    expect($lines[0])->toBe('````')
        ->and($lines[array_key_last($lines)])->toBe('````')
        // Тело доехало дословно и целиком лежит МЕЖДУ ограждениями → инертно.
        ->and(implode("\n", array_slice($lines, 1, -1)))->toBe($body);
});

/*
 * RV-01, вторая половина: attrs.language — в CANONICAL_ATTRIBUTES, store-time его ЗНАЧЕНИЕ не
 * валидирует. Info-string печаталась сырой, поэтому `"php\n```\n<img src=x onerror=alert(1)>"`
 * закрывал fence переводом строки и выпускал живой <img onerror> в .md.
 */
it('markdown-экспорт: language кодоблока валидируется, а не печатается сырым, RV-01', function (string $language, string $expectedFence): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'codeBlock',
            'attrs' => ['language' => $language],
            'content' => [['type' => 'text', 'text' => 'echo 1;']],
        ]],
    ]);

    $markdown = app(TipTapRenderer::class)->toMarkdown($doc);
    $lines = explode("\n", trim($markdown));

    expect($lines[0])->toBe($expectedFence)
        ->and($lines)->toHaveCount(3)
        ->and($lines[1])->toBe('echo 1;')
        ->and($markdown)->not->toContain('onerror');
})->with([
    // Легальный язык доезжает как info-string.
    'обычный язык' => ['php', '```php'],
    'дефис и плюс' => ['objective-c++', '```objective-c++'],
    // Всё, что несёт что-то вне [A-Za-z0-9_+-], инфо-строкой не становится вовсе.
    'перевод строки + payload' => ["php\n```\n<img src=x onerror=alert(1)>", '```'],
    'пробел и разметка' => ['php <img src=x onerror=alert(1)>', '```'],
    'бэктик' => ['php`', '```'],
]);

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

/**
 * Ширина каждой строки GFM-таблицы в КОЛОНКАХ — по неэкранированным '|', ровно как их
 * читает GFM. Свойство, а не подстрока: тест доказывает, что ни одна ячейка не может
 * добавить таблице колонку, каким бы путём в неё ни попал '|'.
 *
 * @return list<int>
 */
function markdownTableWidths(string $markdown): array
{
    $widths = [];

    foreach (explode("\n", $markdown) as $line) {
        $line = trim($line);

        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            continue;
        }

        $inner = substr($line, 1, -1);
        $cells = preg_split('/(?<!\\\\)\|/', $inner);

        $widths[] = is_array($cells) ? count($cells) : 0;
    }

    return $widths;
}

/**
 * Таблица 2×2 с произвольным содержимым первой ячейки тела.
 *
 * @param array<string, mixed> $cellContent
 */
function tableWithCell(array $cellContent): TipTapContent
{
    $cell = fn (string $type, array $inline): array => [
        'type' => $type,
        'content' => [['type' => 'paragraph', 'content' => $inline]],
    ];

    return TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'table',
            'content' => [
                ['type' => 'tableRow', 'content' => [
                    $cell('tableHeader', [['type' => 'text', 'text' => 'a']]),
                    $cell('tableHeader', [['type' => 'text', 'text' => 'b']]),
                ]],
                ['type' => 'tableRow', 'content' => [
                    $cell('tableCell', [$cellContent]),
                    $cell('tableCell', [['type' => 'text', 'text' => 'y']]),
                ]],
            ],
        ]],
    ]);
}

it('markdown-экспорт: "|" в ЦЕЛИ ссылки/картинки не добавляет таблице колонок, P5.2', function (array $cellContent): void {
    // Схема легальна (http) — store-time такой URL принимает; но цель ссылки идёт мимо
    // escapeMarkdown(), а markdownDestination() кодировал только <, > и пробел. GFM читал
    // '| [x](https://a.example/?a=1|2|3) | y |' как ЧЕТЫРЕ колонки и сдвигал всё дальше.
    $markdown = app(TipTapRenderer::class)->toMarkdown(tableWithCell($cellContent));

    // header + разделитель + строка тела — все ровно по 2 колонки.
    expect(markdownTableWidths($markdown))->toBe([2, 2, 2]);
})->with([
    'ссылка' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example/?a=1|2|3']]]]],
    'картинка' => [['type' => 'image', 'attrs' => ['src' => 'https://a.example/i.png?a=1|2', 'alt' => 'x']]],
    'ссылка со скобкой и пайпом' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example/(x)?a=1|2']]]]],
]);
