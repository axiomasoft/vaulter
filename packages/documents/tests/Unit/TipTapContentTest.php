<?php

declare(strict_types=1);

use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\ValueObjects\TipTapContent;

it('accepts a valid TipTap document', function (): void {
    $content = TipTapContent::fromArray(['type' => 'doc', 'content' => []]);

    expect($content->toArray())->toBe(['type' => 'doc', 'content' => []]);
});

it('rejects a non-doc root node', function (): void {
    TipTapContent::fromArray(['type' => 'paragraph']);
})->throws(InvalidDocumentContentException::class);

it('builds an empty document', function (): void {
    expect(TipTapContent::empty()->toArray())->toBe(['type' => 'doc', 'content' => []]);
});

it('passes the size guard when unlimited', function (): void {
    expect(TipTapContent::empty()->ensureWithin(0))->toBeInstanceOf(TipTapContent::class);
});

it('throws when the content exceeds the size limit', function (): void {
    $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('x', 100)]]];
    $large = ['type' => 'doc', 'content' => array_fill(0, 2000, $paragraph)];

    TipTapContent::fromArray($large)->ensureWithin(1);
})->throws(InvalidDocumentContentException::class);

// ─── F-P1.11-02 — closed node/mark/attribute allow-list (store time) ────────

it('rejects an unknown node type', function (): void {
    TipTapContent::fromArray(['type' => 'doc', 'content' => [['type' => 'script']]]);
})->throws(InvalidDocumentContentException::class);

it('rejects an unknown mark type', function (): void {
    // NB: 'underline' USED to be the example here — it is canonical since VLT-REMED P5.2
    // (D27: StarterKit 3.x ships Underline). A genuinely unknown mark still fails closed.
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'blink']]]],
        ]],
    ]);
})->throws(InvalidDocumentContentException::class);

it('rejects an attribute outside the allow-list for the node type', function (): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['align' => 'center'], 'content' => []]],
    ]);
})->throws(InvalidDocumentContentException::class);

it('accepts the full allowed node/mark set', function (): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold'], ['type' => 'italic'], ['type' => 'strike'], ['type' => 'code'], ['type' => 'underline']]],
                ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
                ['type' => 'hardBreak'],
            ]],
            ['type' => 'horizontalRule'],
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]]]],
            ['type' => 'orderedList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]]]],
            ['type' => 'blockquote', 'content' => []],
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'code']]],
            ['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png', 'alt' => 'a', 'title' => 't']],
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    ['type' => 'tableHeader', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null], 'content' => []],
                    ['type' => 'tableCell', 'content' => []],
                ]],
            ]],
        ],
    ]);

    expect($content)->toBeInstanceOf(TipTapContent::class);
});

// ─── R-01 (Blocker) — allow-list ⇄ #[TiptapExtensions]: контракт, не список ──

/*
 * Норма — не литеральный перечень узлов, а КЛАСС: пакет сам объявляет редактору набор
 * extensions через #[TiptapExtensions] на DocumentType. До фикса P0.1 отвергал image,
 * table и underline — узлы, которые пакет САМ отдаёт тулбару, — и документ было НЕВОЗМОЖНО
 * СОХРАНИТЬ. Тест обходит DocumentType::cases() и требует, чтобы характерный узел/марка
 * КАЖДОГО объявленного extension принимались store-time. Добавят extension в enum, забыв
 * про allow-list, — тест покраснеет здесь, а не в проде.
 */
it('store-time allow-list принимает характерный узел КАЖДОГО extension из #[TiptapExtensions], R-01', function (): void {
    // Характерный узел/марка на extension: то, ради чего его подключают.
    $probes = [
        'StarterKit' => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]],
        'Image' => ['type' => 'image', 'attrs' => ['src' => 'https://x.test/a.png']],
        'Table' => ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => []]]],
        ]],
        'Link' => ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test']]]],
        ]],
        'CodeBlock' => ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'x']]],
        // Не производит собственных узлов (плейсхолдер пустого документа) — проверять нечего.
        'Placeholder' => null,
    ];

    $checked = 0;

    foreach (DocumentType::cases() as $type) {
        foreach ($type->tiptapExtensions() as $extension) {
            expect(array_key_exists($extension, $probes))->toBeTrue(
                "DocumentType::{$type->name} объявляет extension [{$extension}], но тест не знает его характерный узел — ".
                'допишите пробу И убедитесь, что allow-list TipTapContent его принимает (D27).',
            );

            $probe = $probes[$extension];

            if ($probe === null) {
                continue;
            }

            expect(fn () => TipTapContent::fromArray(['type' => 'doc', 'content' => [$probe]]))
                ->not->toThrow(InvalidDocumentContentException::class);

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

/*
 * Тест выше берёт пробы ВРУЧНУЮ — а значит, структурно не ловит дрейф АТРИБУТОВ: он пишет
 * link только с href, и что редактор шлёт что-то ещё, узнать неоткуда.
 *
 * А шлёт он ВЕСЬ мешок: ProseMirror-овский toJSON() выгружает все ключи, объявленные в
 * addAttributes() расширения, вместе с дефолтами и null'ами. Пробы ниже — форма, которую
 * сериализует РЕАЛЬНЫЙ редактор на тех версиях, что пакет и отгружает (@tiptap/extension-link
 * ^3, @tiptap/starter-kit ^3): у Link это href/target/rel/class, у OrderedList — start/type.
 * До фикса allow-list синхронизировали с ИМЕНАМИ extension'ов, а не с их атрибутами, и на
 * `class` он спотыкался: ЛЮБОЙ документ со ссылкой было невозможно сохранить — R-01 ровно на
 * один атрибут в сторону.
 *
 * Ограничение (честно): node_modules этой среде недоступен, поэтому пробы сняты с
 * документированного addAttributes() расширений, а не с живого round-trip'а редактора.
 */
it('store-time allow-list принимает АТРИБУТЫ, которые extension реально сериализует, R-01', function (array $node): void {
    expect(fn () => TipTapContent::fromArray(['type' => 'doc', 'content' => [$node]]))
        ->not->toThrow(InvalidDocumentContentException::class);
})->with([
    'Link: весь мешок марки, включая class=null' => [[
        'type' => 'paragraph',
        'content' => [[
            'type' => 'text',
            'text' => 'x',
            'marks' => [['type' => 'link', 'attrs' => [
                'href' => 'https://x.test',
                'target' => '_blank',
                'rel' => 'noopener noreferrer nofollow',
                'class' => null,
            ]]],
        ]],
    ]],
    'OrderedList: start + type' => [[
        'type' => 'orderedList',
        'attrs' => ['start' => 1, 'type' => null],
        'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]]],
    ]],
]);

// ─── D28 — аддитивный конфиг-шов allow-list ────────────────────────────────

it('конфиг-шов ДОБАВЛЯЕТ узлы/марки/атрибуты хоста в allow-list, R-01', function (): void {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'mention', 'attrs' => ['id' => '7'], 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'highlight']]],
        ]],
    ]];

    expect(fn () => TipTapContent::fromArray($doc))->toThrow(InvalidDocumentContentException::class);

    config()->set('vaulter.tiptap.extra_nodes', ['mention']);
    config()->set('vaulter.tiptap.extra_marks', ['highlight']);
    config()->set('vaulter.tiptap.extra_attributes', ['mention' => ['id']]);

    expect(TipTapContent::fromArray($doc))->toBeInstanceOf(TipTapContent::class);
});

it('конфиг-шов АДДИТИВЕН: канон не вычёркивается конфигом, R-01', function (): void {
    // Хост «переопределил» список своим — канон обязан выжить, иначе он молча сломает
    // сохранение любого документа (D28).
    config()->set('vaulter.tiptap.extra_nodes', ['mention']);

    expect(TipTapContent::allowedNodeTypes())->toContain('doc', 'text', 'paragraph', 'image', 'table', 'mention');
});

// ─── R-11 — конфиг-шов не отменяет санитизацию URL ──────────────────────────
//
// Расширение allow-list — вкус хоста; проверка схемы URL — инвариант безопасности.
// Раньше карта URL-несущих атрибутов была хардкодом (link.href + image.src), поэтому
// для узла, добавленного хостом через шов, не работал НИ store-time-контроль схемы,
// НИ read-path-скраб — и javascript: доезжал до редактора через readableContent().

it('конфиг-шов: URL-несущий атрибут ХОСТА проверяется схемой на записи, R-11', function (string $url): void {
    // Хост объявил свой узел с атрибутом src и НЕ регистрировал его как URL-несущий.
    // Барьер обязан сработать сам: имя атрибута URL-несущее — значит, схема проверяется.
    config()->set('vaulter.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter.tiptap.extra_attributes', ['iframe' => ['src']]);

    expect(fn () => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => $url]]],
    ]))->toThrow(InvalidDocumentContentException::class);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data URI' => ['data:text/html,<script>alert(1)</script>'],
    'control-char split' => ["java\tscript:alert(1)"],
]);

it('конфиг-шов: URL-атрибут под неочевидным именем проверяется, если хост его объявил, R-11', function (): void {
    // Имя `location` само по себе ни о чём не говорит — для таких случаев есть шов
    // vaulter.tiptap.url_attributes: объявленный URL-атрибут проходит ту же проверку.
    config()->set('vaulter.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter.tiptap.extra_attributes', ['embed' => ['location']]);
    config()->set('vaulter.tiptap.url_attributes', ['embed' => ['location']]);

    expect(fn () => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['location' => 'javascript:alert(1)']]],
    ]))->toThrow(InvalidDocumentContentException::class);
});

it('конфиг-шов: URL-атрибут хоста с разрешённой схемой принимается, R-11', function (): void {
    // Барьер не должен превращаться в отказ в обслуживании: https проходит.
    config()->set('vaulter.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter.tiptap.extra_attributes', ['iframe' => ['src', 'title']]);

    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => 'https://a.example/v', 'title' => 'ok']]],
    ]);

    expect($content->toArray()['content'][0]['attrs']['src'])->toBe('https://a.example/v');
});

it('конфиг-шов: withSanitizedUrls() вычищает URL-атрибут хоста на чтении, R-11', function (): void {
    // Легаси-снимок (записан до барьера / при другом конфиге) декеится на чтении с
    // сохранением значений — и уходит в редактор через DocumentRevisionResource.
    // Скраб JSON-пути обязан покрывать и хостовые URL-атрибуты, а не только канон.
    config()->set('vaulter.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter.tiptap.extra_attributes', ['iframe' => ['src']]);

    $scrubbed = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => 'javascript:alert(1)']]],
    ])->withSanitizedUrls();

    // Атрибута нет — а раз он был у узла единственным, нет и самого ключа attrs.
    expect($scrubbed->toArray()['content'][0]['attrs'] ?? [])->not->toHaveKey('src');
});

it('конфиг-шов НЕ расширяет схемы URL-несущих атрибутов, R-01', function (): void {
    // Инвариант безопасности, а не вкус хоста: даже объявив href «своим» атрибутом,
    // хост не может протащить javascript: (D28).
    config()->set('vaulter.tiptap.extra_attributes', ['link' => ['href']]);

    expect(fn () => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
        ]],
    ]))->toThrow(InvalidDocumentContentException::class);
});

// ─── F-P1.11-01 — URL scheme allow-list (link.href + image.src) ─────────────

it('rejects a disallowed link href scheme', function (string $href): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]],
        ]],
    ]);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'vbscript' => ['vbscript:msgbox(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'missing href' => [''],
])->throws(InvalidDocumentContentException::class);

it('rejects a disallowed image src scheme, R-01', function (string $src): void {
    TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['src' => $src]]],
    ]);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data URI' => ['data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg=='],
    'missing src' => [''],
])->throws(InvalidDocumentContentException::class);

it('accepts http, https and mailto link href schemes', function (string $href): void {
    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]],
        ]],
    ]);

    expect($content)->toBeInstanceOf(TipTapContent::class);
})->with([
    'http' => ['http://x.test'],
    'https' => ['https://x.test'],
    'mailto' => ['mailto:a@x.test'],
]);

// ─── D29 — decayFrom(): чтение деградирует, а не бросает ────────────────────

it('decayFrom() вырезает неизвестные узлы/марки/атрибуты и НЕ бросает, R-01', function (): void {
    $content = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'paragraph', 'attrs' => ['style' => 'color:red'], 'content' => [
                ['type' => 'text', 'text' => 'ok', 'marks' => [['type' => 'bold'], ['type' => 'blink']]],
            ]],
        ],
    ]);

    expect($content->toArray())->toBe([
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'ok', 'marks' => [['type' => 'bold']]],
            ]],
        ],
    ]);
});

it('decayFrom() СОХРАНЯЕТ значения атрибутов (вход второго эшелона D4), R-07', function (): void {
    // Значение href НЕ вычищается на чтении: его обезвреживает render-time санитайзер.
    // Вычистить здесь — значит оставить эшелон 2 без входа и сделать его непроверяемым.
    $content = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
        ]],
    ]);

    $mark = $content->toArray()['content'][0]['content'][0]['marks'][0];

    expect($mark['attrs']['href'])->toBe('javascript:alert(1)');
});

it('decayFrom() на мусорном корне отдаёт пустой doc, а не бросает, R-01', function (mixed $doc): void {
    expect(TipTapContent::decayFrom($doc)->toArray())->toBe(['type' => 'doc', 'content' => []]);
})->with([
    'not a doc' => [['type' => 'paragraph']],
    'no type' => [['content' => []]],
    'content is a scalar' => [['type' => 'doc', 'content' => 'oops']],
]);

// ─── P5.2 — круг чтение→сохранение и fail-closed по ЗНАЧЕНИЮ ────────────────
//
// Два инварианта, которые R-12/R-11 оставили открытыми:
//
//  1. То, что пакет САМ отдал редактору (DocumentRevisionResource::readableContent() =
//     decayFrom() + withSanitizedUrls()), обязано сохраняться обратно. Скраб, который
//     выкусывал href/src — ОБЯЗАТЕЛЬНЫЕ атрибуты — оставлял узел без них, и fromArray()
//     ронял PATCH: любой легаси-документ с относительной ссылкой становился НЕСОХРАНЯЕМЫМ.
//  2. XSS-барьер обязан покрывать и то, что хост добавил через шов. Эвристика по ИМЕНИ
//     атрибута fail-OPEN для всего, чего в списке имён нет ('source', 'dataSrc', …).

it('read→save: контент, отданный редактору, принимается обратно на записи, P5.2', function (array $legacy): void {
    $readable = TipTapContent::decayFrom($legacy)->withSanitizedUrls()->toArray();

    expect(TipTapContent::fromArray($readable))->toBeInstanceOf(TipTapContent::class);
})->with([
    'относительная ссылка' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/pricing', 'target' => '_blank']]]]],
    ]]]],
    'анкор' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#top']]]]],
    ]]]],
    'javascript: в href' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
    ]]]],
    'ссылка вовсе без href' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['target' => '_blank']]]]],
    ]]]],
    'относительный src картинки' => [['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => '/img/logo.png', 'alt' => 'logo']],
    ]]],
    'картинка без атрибутов' => [['type' => 'doc', 'content' => [['type' => 'image']]]],
    'битый level заголовка' => [['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 'два'], 'content' => [['type' => 'text', 'text' => 'x']]],
    ]]],
]);

it('read→save: скраб роняет МАРКУ ссылки, а не оставляет её без href, P5.2', function (): void {
    // Марка уходит целиком — текст остаётся читаемым, документ сохраняем.
    $readable = TipTapContent::decayFrom(['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
    ]]])->withSanitizedUrls()->toArray();

    expect($readable['content'][0]['content'][0])
        ->toHaveKey('text', 'x')
        ->not->toHaveKey('marks');
});

it('read→save: скраб роняет УЗЕЛ картинки без пригодного src, P5.2', function (): void {
    $readable = TipTapContent::decayFrom(['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'x']],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]],
    ]])->withSanitizedUrls()->toArray();

    expect($readable['content'])->toHaveCount(1)
        ->and($readable['content'][0]['type'])->toBe('paragraph');
});

it('fail-closed по значению: опасная схема в ЛЮБОМ атрибуте хоста отвергается на записи, P5.2', function (string $attribute, string $value): void {
    // Имя атрибута ни о чём не говорит — решает ЗНАЧЕНИЕ: браузер резолвит javascript:
    // независимо от того, как хост назвал атрибут в своём расширении.
    config()->set('vaulter.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter.tiptap.extra_attributes', ['embed' => [$attribute]]);

    expect(fn () => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => [$attribute => $value]]],
    ]))->toThrow(InvalidDocumentContentException::class);
})->with([
    'source' => ['source', 'javascript:alert(1)'],
    'dataSrc' => ['dataSrc', 'vbscript:msgbox(1)'],
    'data URI под чужим именем' => ['payload', 'data:text/html,<script>alert(1)</script>'],
    'обфускация табом' => ['source', "java\tscript:alert(1)"],
]);

it('fail-closed по значению: опасная схема в атрибуте хоста вычищается на чтении, P5.2', function (): void {
    config()->set('vaulter.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter.tiptap.extra_attributes', ['embed' => ['source']]);

    $readable = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['source' => 'javascript:alert(1)']]],
    ])->withSanitizedUrls()->toArray();

    expect($readable['content'][0])->not->toHaveKey('attrs');
});

it('fail-closed по значению: безобидные атрибуты хоста не трогаются, P5.2', function (): void {
    config()->set('vaulter.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter.tiptap.extra_attributes', ['embed' => ['source', 'caption']]);

    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['source' => 'https://a.example/v', 'caption' => 'подпись']]],
    ]);

    expect($content->withSanitizedUrls()->toArray()['content'][0]['attrs'])
        ->toBe(['source' => 'https://a.example/v', 'caption' => 'подпись']);
});
