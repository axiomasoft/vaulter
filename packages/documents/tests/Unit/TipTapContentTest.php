<?php

declare(strict_types=1);

use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;

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

// ─── closed node/mark/attribute allow-list (store time) ────────

it('rejects an unknown node type', function (): void {
    TipTapContent::fromArray(['type' => 'doc', 'content' => [['type' => 'script']]]);
})->throws(InvalidDocumentContentException::class);

it('rejects an unknown mark type', function (): void {
    // NB: 'underline' USED to be the example here — it is canonical since
    // (StarterKit 3.x ships Underline). A genuinely unknown mark still fails closed.
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

// ─── allow-list ⇄ editor preset: a contract, not a list ──

/*
 * The rule is not a literal list of nodes but a CLASS: every TipTap document type hands the
 * editor a preset (viewer vaulter/tiptap, props.preset), and the type's schema must accept the
 * characteristic node or mark of EACH extension of that preset. Before the fix the allow-list rejected
 * image, table and underline, nodes the package ITSELF gives the toolbar, so the document
 * could NOT BE SAVED. Add an extension to the preset and forget the schema, and this test turns red.
 */
it('each preset schema accepts a characteristic node of EACH of its extensions', function (TipTapSchema $schema, array $extensions): void {
    // The characteristic node or mark per extension: what it is enabled for.
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
    ];

    foreach ($extensions as $extension) {
        expect(fn (): TipTapContent => TipTapContent::fromArray(['type' => 'doc', 'content' => [$probes[$extension]]], $schema))
            ->not->toThrow(InvalidDocumentContentException::class);
    }
})->with([
    'rich-text' => [fn (): TipTapSchema => TipTapSchema::richText(), ['StarterKit', 'Image', 'Table', 'Link', 'CodeBlock']],
    'markdown' => [fn (): TipTapSchema => TipTapSchema::markdown(), ['StarterKit', 'Link', 'CodeBlock']],
]);

it('reports loss on read: lossless only when nothing was dropped', function (): void {
    [, $kept] = TipTapContent::decayReporting(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'attrs' => [], 'content' => [['type' => 'text']]],
    ]]);
    [$decayed, $lost] = TipTapContent::decayReporting(['type' => 'doc', 'content' => [
        ['type' => 'paragraph'],
        ['type' => 'acmeWidget'],
    ]]);
    [, $lostMark] = TipTapContent::decayReporting(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'acmeMark']]]]],
    ]]);
    [, $lostAttribute] = TipTapContent::decayReporting(['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1, 'acme' => true]],
    ]]);
    [, $notADoc] = TipTapContent::decayReporting(['type' => 'paragraph']);

    expect($kept)->toBeTrue()
        ->and($lost)->toBeFalse()
        ->and(array_column($decayed->toArray()['content'], 'type'))->toBe(['paragraph'])
        ->and($lostMark)->toBeFalse()
        ->and($lostAttribute)->toBeFalse()
        ->and($notADoc)->toBeFalse();
});

/*
 * The test above picks its samples BY HAND, so structurally it cannot catch ATTRIBUTE drift: it writes
 * link with href only, and has no way of knowing that the editor sends anything else.
 *
 * And it sends the WHOLE bag: ProseMirror's toJSON() dumps every key declared in the
 * extension's addAttributes(), defaults and nulls included. The samples below are the shape the
 * REAL editor serializes on the versions the package ships (@tiptap/extension-link
 * ^3, @tiptap/starter-kit ^3): href/target/rel/class for Link, start/type for OrderedList.
 * Before the fix the allow-list was synced with the extension NAMES rather than their attributes, and
 * it tripped on `class`: ANY document with a link could not be saved, by exactly
 * one attribute.
 *
 * A stated limitation: the samples come from the documented addAttributes() of the extensions,
 * not from a live editor round trip, because the PHP suite does not run the editor.
 */
it('the store-time allow-list accepts the ATTRIBUTES an extension actually serializes', function (array $node): void {
    expect(fn (): TipTapContent => TipTapContent::fromArray(['type' => 'doc', 'content' => [$node]]))
        ->not->toThrow(InvalidDocumentContentException::class);
})->with([
    'Link: the whole mark bag, class=null included' => [[
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

// ─── additive config seam of the allow-list ─────────────────────────

it('the config seam ADDS host nodes, marks and attributes to the allow-list', function (): void {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'mention', 'attrs' => ['id' => '7'], 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'highlight']]],
        ]],
    ]];

    expect(fn (): TipTapContent => TipTapContent::fromArray($doc))->toThrow(InvalidDocumentContentException::class);

    config()->set('vaulter-documents.tiptap.extra_nodes', ['mention']);
    config()->set('vaulter-documents.tiptap.extra_marks', ['highlight']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['mention' => ['id']]);

    expect(TipTapContent::fromArray($doc))->toBeInstanceOf(TipTapContent::class);
});

it('the config seam is ADDITIVE: config cannot strike the canon out', function (): void {
    // The host "overrode" the list with its own; the canon must survive, otherwise it silently breaks
    // saving of every document.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['mention']);

    expect(TipTapContent::allowedNodeTypes())->toContain('doc', 'text', 'paragraph', 'image', 'table', 'mention');
});

// ─── the config seam does not cancel URL sanitization ──────────────
//
// Extending the allow-list is the host's taste; checking the URL scheme is a security invariant.
// The map of URL-bearing attributes used to be hard-coded (link.href + image.src), so
// for a node the host added through the seam NEITHER the store-time scheme check
// NOR the read-path scrub worked, and javascript: reached the editor through readableContent().

it('config seam: a HOST URL-bearing attribute is scheme-checked on write', function (string $url): void {
    // The host declared its own node with a src attribute and did NOT register it as URL-bearing.
    // The barrier must fire on its own: the attribute name is URL-bearing, so the scheme is checked.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['iframe' => ['src']]);

    expect(fn (): TipTapContent => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => $url]]],
    ]))->toThrow(InvalidDocumentContentException::class);
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data URI' => ['data:text/html,<script>alert(1)</script>'],
    'control-char split' => ["java\tscript:alert(1)"],
]);

it('config seam: a URL attribute under a non-obvious name is checked when the host declares it', function (): void {
    // The name `location` says nothing by itself; for such cases there is the
    // vaulter-documents.tiptap.url_attributes seam: a declared URL attribute gets the same check.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['embed' => ['location']]);
    config()->set('vaulter-documents.tiptap.url_attributes', ['embed' => ['location']]);

    expect(fn (): TipTapContent => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['location' => 'javascript:alert(1)']]],
    ]))->toThrow(InvalidDocumentContentException::class);
});

it('config seam: a host URL attribute with an allowed scheme is accepted', function (): void {
    // The barrier must not turn into a denial of service: https passes.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['iframe' => ['src', 'title']]);

    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => 'https://a.example/v', 'title' => 'ok']]],
    ]);

    expect($content->toArray()['content'][0]['attrs']['src'])->toBe('https://a.example/v');
});

it('config seam: withSanitizedUrls() scrubs a host URL attribute on read', function (): void {
    // A legacy snapshot (written before the barrier or under another config) decays on read
    // keeping its values, and goes to the editor through DocumentRevisionResource.
    // The scrub of the JSON path must cover host URL attributes too, not only the canon.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['iframe']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['iframe' => ['src']]);

    $scrubbed = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'iframe', 'attrs' => ['src' => 'javascript:alert(1)']]],
    ])->withSanitizedUrls();

    // The attribute is gone, and since it was the node's only one, so is the attrs key itself.
    expect($scrubbed->toArray()['content'][0]['attrs'] ?? [])->not->toHaveKey('src');
});

it('the config seam does NOT widen the schemes of URL-bearing attributes', function (): void {
    // A security invariant, not the host's taste: even after declaring href as its "own" attribute
    // the host cannot smuggle javascript: in.
    config()->set('vaulter-documents.tiptap.extra_attributes', ['link' => ['href']]);

    expect(fn (): TipTapContent => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
        ]],
    ]))->toThrow(InvalidDocumentContentException::class);
});

// ─── URL scheme allow-list (link.href + image.src) ─────────────

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

// ─── decayFrom(): reads degrade instead of throwing ────────────────

it('decayFrom() strips unknown nodes, marks and attributes and does NOT throw', function (): void {
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

it('decayFrom() KEEPS attribute values (they feed the render-time sanitizer)', function (): void {
    // The href value is NOT scrubbed on read: the render-time sanitizer neutralizes it.
    // Scrubbing it here would leave the second line of defence without input and make it untestable.
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

it('decayFrom() on a garbage root returns an empty doc instead of throwing', function (mixed $doc): void {
    expect(TipTapContent::decayFrom($doc)->toArray())->toBe(['type' => 'doc', 'content' => []]);
})->with([
    'not a doc' => [['type' => 'paragraph']],
    'no type' => [['content' => []]],
    'content is a scalar' => [['type' => 'doc', 'content' => 'oops']],
]);

// ─── read→save round trip and fail-closed by VALUE ─────────────────
//
// Two invariants the earlier checks left open:
//
//  1. What the package ITSELF gave the editor (DocumentRevisionResource::readableContent() =
//     decayFrom() + withSanitizedUrls()) must save back. A scrub that
//     cut href/src, REQUIRED attributes, left the node without them, and fromArray()
//     failed the PATCH: any legacy document with a relative link became UNSAVABLE.
//  2. The XSS barrier must also cover what the host added through the seam. A heuristic on the attribute
//     NAME fails OPEN for everything missing from the name list ('source', 'dataSrc', …).

it('read then save: content given to the editor is accepted back on write', function (array $legacy): void {
    $readable = TipTapContent::decayFrom($legacy)->withSanitizedUrls()->toArray();

    expect(TipTapContent::fromArray($readable))->toBeInstanceOf(TipTapContent::class);
})->with([
    'relative link' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/pricing', 'target' => '_blank']]]]],
    ]]]],
    'anchor' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#top']]]]],
    ]]]],
    'javascript: in href' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
    ]]]],
    'link without any href' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['target' => '_blank']]]]],
    ]]]],
    'relative image src' => [['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => '/img/logo.png', 'alt' => 'logo']],
    ]]],
    'image without attributes' => [['type' => 'doc', 'content' => [['type' => 'image']]]],
    'broken heading level' => [['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 'два'], 'content' => [['type' => 'text', 'text' => 'x']]],
    ]]],
]);

it('read then save: the scrub drops the link MARK instead of leaving it without href', function (): void {
    // The mark goes away entirely: the text stays readable and the document can be saved.
    $readable = TipTapContent::decayFrom(['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
    ]]])->withSanitizedUrls()->toArray();

    expect($readable['content'][0]['content'][0])
        ->toHaveKey('text', 'x')
        ->not->toHaveKey('marks');
});

it('read then save: the scrub drops an image NODE without a usable src', function (): void {
    $readable = TipTapContent::decayFrom(['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'x']],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]],
    ]])->withSanitizedUrls()->toArray();

    expect($readable['content'])->toHaveCount(1)
        ->and($readable['content'][0]['type'])->toBe('paragraph');
});

it('fail-closed by value: a dangerous scheme in ANY host attribute is rejected on write', function (string $attribute, string $value): void {
    // The attribute name says nothing; the VALUE decides: a browser resolves javascript:
    // regardless of what the host named the attribute in its extension.
    config()->set('vaulter-documents.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['embed' => [$attribute]]);

    expect(fn (): TipTapContent => TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => [$attribute => $value]]],
    ]))->toThrow(InvalidDocumentContentException::class);
})->with([
    'source' => ['source', 'javascript:alert(1)'],
    'dataSrc' => ['dataSrc', 'vbscript:msgbox(1)'],
    'data URI under a foreign name' => ['payload', 'data:text/html,<script>alert(1)</script>'],
    'tab obfuscation' => ['source', "java\tscript:alert(1)"],
]);

it('fail-closed by value: a dangerous scheme in a host attribute is scrubbed on read', function (): void {
    config()->set('vaulter-documents.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['embed' => ['source']]);

    $readable = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['source' => 'javascript:alert(1)']]],
    ])->withSanitizedUrls()->toArray();

    expect($readable['content'][0])->not->toHaveKey('attrs');
});

it('fail-closed by value: harmless host attributes are left alone', function (): void {
    config()->set('vaulter-documents.tiptap.extra_nodes', ['embed']);
    config()->set('vaulter-documents.tiptap.extra_attributes', ['embed' => ['source', 'caption']]);

    $content = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [['type' => 'embed', 'attrs' => ['source' => 'https://a.example/v', 'caption' => 'подпись']]],
    ]);

    expect($content->withSanitizedUrls()->toArray()['content'][0]['attrs'])
        ->toBe(['source' => 'https://a.example/v', 'caption' => 'подпись']);
});

// ─── Strict and lenient read agree on what a TipTap node is (Grok 4.7 review) ──
//
// A node is {type, attrs, content | text, marks}. Whatever fromArray() accepts must read
// back without loss, or the next save of the read drops it; whatever it rejects reads as
// lossy. Marks sit on any node, as in ProseMirror JSON (an inline image or hard break
// inherits the marks around it), and are checked against the same allow-list.

it('round-trips everything fromArray() accepts without loss', function (array $doc): void {
    config()->set('vaulter-documents.tiptap.extra_attributes', ['doc' => ['lang']]);
    TipTapContent::fromArray($doc);
    [$read, $lossless] = TipTapContent::decayReporting($doc);

    expect($lossless)->toBeTrue()
        ->and($read->toArray())->toBe($doc);
})->with([
    'marks on a hard break' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold']]],
        ['type' => 'hardBreak', 'marks' => [['type' => 'bold']]],
    ]]]]],
    'link mark on an image' => [['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png'], 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
    ]]],
    'root attrs the host allows' => [['type' => 'doc', 'attrs' => ['lang' => 'ru'], 'content' => []]],
]);

it('rejects at store what a read would drop', function (array $doc): void {
    expect(fn (): TipTapContent => TipTapContent::fromArray($doc))->toThrow(InvalidDocumentContentException::class);
    expect(TipTapContent::decayReporting($doc)[1])->toBeFalse();
})->with([
    'root attrs outside the allow-list' => [['type' => 'doc', 'attrs' => ['meta' => 'x'], 'content' => []]],
    'unknown root field' => [['type' => 'doc', 'content' => [], 'meta' => ['keep' => 'me']]],
    'unknown node field' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'id' => 'p1']]]],
    'non-string text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => ['keep' => 'me']]]]]]],
    'content on a text node' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a', 'content' => [['type' => 'text', 'text' => 'b']]]]]]]],
    'scalar attrs' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => 'x']]]],
    'unknown mark on a non-text node' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'marks' => [['type' => 'blink']]]]]],
]);

it('checks the URL of a mark on a non-text node at store time, as on text', function (): void {
    TipTapContent::fromArray(['type' => 'doc', 'content' => [
        ['type' => 'hardBreak', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
    ]]);
})->throws(InvalidDocumentContentException::class);

it('keeps lossless the normalisations that drop nothing', function (array $doc): void {
    expect(fn (): TipTapContent => TipTapContent::fromArray($doc))->not->toThrow(InvalidDocumentContentException::class);
    expect(TipTapContent::decayReporting($doc)[1])->toBeTrue();
})->with([
    'empty root attrs' => [['type' => 'doc', 'attrs' => [], 'content' => []]],
    'text without text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text']]]]]],
    'null marks and content' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'marks' => null, 'content' => null]]]],
]);
