<?php

declare(strict_types=1);

use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * VLT-REMED P5.2 / R-12 — ГЛАВНЫЙ путь чтения контента (GET nodes/{node}/content →
 * NodeDocumentVersionResource), тот самый, который монтирует в редактор SPA-компонент
 * VaulterDocumentNode.vue.
 *
 * Оба эшелона защиты от stored XSS (D4) мимо него: первый (store-time TipTapContent)
 * не видит строк, записанных ДО его появления, второй (symfony/html-sanitizer) стоит
 * только на TipTapRenderer::toHtml() — то есть на экспорте/превью. Ресурс же отдавал
 * СЫРОЙ `$version->content`, поэтому легаси-строка с `link.href="javascript:…"`
 * приезжала в редактор как есть.
 *
 * Контракт после фикса: TipTap-контент на чтении ДЕКЕЙЯТСЯ (неизвестные узлы/марки/
 * атрибуты срезаются) и его URL-атрибуты ПРОСКРЕБАЮТСЯ по LinkSchemeValidator; опаковый
 * снимок не-rich-text хендлера (Univer и т.п.) по-прежнему возвращается дословно.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

function readPathStorage(): Storage
{
    return Vaulter::for(DriveTestHelpers::host())->storage();
}

/**
 * Легаси-строка: писалась до появления store-time allow-list, поэтому несёт и опасный
 * href, и узел/атрибут, которых в каноне нет.
 *
 * @param array<string, mixed> $content
 */
function seedLegacyHeadContent(int|string $nodeId, array $content): void
{
    $head = NodeDocumentVersion::query()->where('node_id', $nodeId)->orderByDesc('version_no')->firstOrFail();

    NodeDocumentVersion::query()->whereKey($head->getKey())->update(['content' => json_encode($content)]);
}

it('отдаёт легаси-контент с javascript:-ссылкой обеззараженным, а не сырым, R-12', function (): void {
    $base = 'api/vaulter/storages/'.readPathStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', ['name' => 'Legacy'])
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
            ['type' => 'image', 'attrs' => ['src' => "java\tscript:alert(2)", 'alt' => 'x']],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(3)']]],
        ],
    ]);

    $content = $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')
        ->assertOk()
        ->json('data.content');

    $encoded = (string) json_encode($content);

    // До фикса ресурс отдавал строку дословно: и javascript:-href, и обойдённый через
    // управляющий символ `java\tscript:`, и левый узел `script`, и левый атрибут.
    expect($encoded)->not->toContain('javascript:')
        ->and($encoded)->not->toContain('script:alert')
        ->and($encoded)->not->toContain('onclick')
        ->and($encoded)->not->toContain('"script"');

    // Декей — не выжженная земля: текст и разрешённые атрибуты на месте.
    expect($encoded)->toContain('click')
        ->and($encoded)->toContain('"alt":"x"');
});

it('сохраняет безопасные ссылки при чтении — обеззараживание не ломает нормальный контент, R-12', function (): void {
    $base = 'api/vaulter/storages/'.readPathStorage()->getKey();

    $nodeId = $this->postJson($base.'/documents', [
        'name' => 'Safe',
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'docs', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'https://example.test/a', 'target' => '_blank']],
                ]],
            ]],
        ]],
    ])->assertCreated()->json('data.id');

    $content = $this->getJson('api/vaulter/nodes/'.$nodeId.'/content')->assertOk()->json('data.content');

    expect((string) json_encode($content))->toContain('https:\/\/example.test\/a')
        ->and($content['content'][0]['content'][0]['marks'][0]['attrs']['target'])->toBe('_blank');
});
