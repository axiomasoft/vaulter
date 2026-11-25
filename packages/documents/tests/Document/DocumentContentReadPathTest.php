<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The MAIN content read path (GET documents/{node}/content → DocumentContentResource), the one the SPA editor component mounts
 * VaulterDocumentNode.vue.
 *
 * Both lines of defence against stored XSS missed it: the first (store-time TipTapContent)
 * does not see rows written BEFORE it existed, and the second (symfony/html-sanitizer) sits
 * only on TipTapRenderer::toHtml(), i.e. on export and preview. The resource returned the
 * RAW `$version->content`, so a legacy row with `link.href="javascript:…"`
 * reached the editor as is.
 *
 * The contract after the fix: TipTap content DECAYS on read (unknown nodes, marks and
 * attributes are stripped) and its URL attributes are SCRUBBED by LinkSchemeValidator; the opaque
 * snapshot of a non-rich-text handler (Univer and the like) is still returned verbatim.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function readPathStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

/**
 * A legacy row: written before the store-time allow-list existed, so it carries both a dangerous
 * href and a node and an attribute that are not in the canon.
 *
 * @param array<string, mixed> $content
 */
function seedLegacyHeadContent(int|string $nodeId, array $content): void
{
    // The content endpoint reads the working state; seed it as old code stored it.
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $nodeId)->firstOrFail()->payload()->update(['content' => json_encode($content)]));
}

it('serves legacy content with a javascript: link sanitized, not raw', function (): void {
    $drive = readPathStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), ['name' => 'Legacy'])
        ->assertCreated()
        ->json('data.id');

    seedLegacyHeadContent($nodeId, [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'click',
                        'marks' => [
                            ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)', 'onclick' => 'evil()']],
                        ],
                    ],
                ],
            ],
            ['type' => 'image', 'attrs' => ['src' => "java\tscript:alert(2)", 'alt' => 'evil']],
            ['type' => 'image', 'attrs' => ['src' => 'https://cdn.test/ok.png', 'alt' => 'x']],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(3)']]],
        ],
    ]);

    $content = $this->getJson(Docs::url('documents/'.$nodeId.'/content'))
        ->assertOk()
        ->json('data.content');

    $encoded = (string) json_encode($content);

    // Before the fix the resource returned the row verbatim: the javascript: href, the one bypassed with
    // the control character `java\tscript:`, the stray `script` node and the stray attribute.
    expect($encoded)->not->toContain('javascript:')
        ->and($encoded)->not->toContain('script:alert')
        ->and($encoded)->not->toContain('onclick')
        ->and($encoded)->not->toContain('"script"');

    // An image with a dangerous src goes away ENTIRELY (a node without its required src is not kept,
    // see the round trip below), while the honest one next to it stays.
    expect($encoded)->not->toContain('"alt":"evil"');

    // Decay is not scorched earth: the text and the allowed attributes are in place.
    expect($encoded)->toContain('click')
        ->and($encoded)->toContain('"alt":"x"');
});

it('read then save round trip: what the resource gave the editor it accepts back', function (array $legacy): void {
    // The URL scrub used to cut href/src, REQUIRED attributes, so the editor got
    // a node like {"type":"link","attrs":{"target":"_blank"}} from GET. A PUT of exactly that
    // payload failed with 500 (store time requires href): any legacy document with a relative
    // link became UNSAVABLE forever. The test runs exactly that round trip over the live routes.
    $drive = readPathStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), ['name' => 'Legacy'])->assertCreated()->json('data.id');

    seedLegacyHeadContent($nodeId, $legacy);

    $content = $this->getJson(Docs::url('documents/'.$nodeId.'/content'))->assertOk()->json('data.content');

    // Accepted back as-is; an unchanged document is a no-op, a changed one autosaves.
    Docs::put($nodeId, $content)->assertOk();
})->with([
    'relative link' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'pricing', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/pricing', 'target' => '_blank']]]]],
    ]]]],
    'javascript: in a link' => [['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]],
    ]]]],
    'relative image src' => [['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => '/img/logo.png', 'alt' => 'logo']],
    ]]],
]);

it('keeps safe links on read, so sanitizing does not break normal content', function (): void {
    $drive = readPathStorage()->getKey();

    $nodeId = $this->postJson(Docs::url('drives/'.$drive.'/documents'), [
        'name' => 'Safe',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'docs', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example.test/a', 'target' => '_blank']],
                ]],
            ]],
        ]],
    ])->assertCreated()->json('data.id');

    $content = $this->getJson(Docs::url('documents/'.$nodeId.'/content'))->assertOk()->json('data.content');

    expect((string) json_encode($content))->toContain('https:\/\/example.test\/a')
        ->and($content['content'][0]['content'][0]['marks'][0]['attrs']['target'])->toBe('_blank');
});

it('reads the metadata of a page of documents without loading JSON or binary checkpoints', function (): void {
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $documents->create('First document');
    $documents->create('Second document');
    $db = app(VaulterDatabase::class)->connection();
    $db->flushQueryLog();
    $db->enableQueryLog();
    try {
        $page = $drive->nodes()->children();
        $queries = array_column($db->getQueryLog(), 'query');
    } finally {
        $db->disableQueryLog();
        $db->flushQueryLog();
    }

    expect($page->items)->toHaveCount(2)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'v_documents')))->toHaveCount(1)
        ->and(implode("\n", $queries))->not->toContain('v_document_payloads', 'v_document_sync_states', 'v_document_revision_payloads', 'v_document_revision_sync_states');
});
