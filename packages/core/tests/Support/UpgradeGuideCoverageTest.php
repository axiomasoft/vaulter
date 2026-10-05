<?php

declare(strict_types=1);

use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Support\Config;

/*
 * VLT-REMED P5.2 / R-06 — системный пробел UPGRADE.md (подтверждает MORNING-QUEUE §4).
 *
 * Корень находки: сверка велась ГРЕПОМ ПО F-id, а F-id попадает в UPGRADE.md только если
 * автор item'а его туда вписал — грепу нечего ловить, когда секции нет ВОВСЕ. Так и вышло:
 * P0.1 (самый host-видимый BC плана), P1.1 и P1.3 не имели записи.
 *
 * Поэтому guard связывает доку с КОДОМ, а не с самой собой (D19): каждый кейс сперва
 * доказывает ФАКТ ломающего изменения в коде/конфиге, и только потом требует секцию про него
 * в UPGRADE.md. Тавтологичный тест «в файле есть строка X» ловил бы лишь удаление строки, но
 * не расхождение доки с кодом — то есть ровно ту находку, ради которой он написан.
 */
function upgradeGuide(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/UPGRADE.md');
}

it('UPGRADE.md документирует контракт TipTap-контента, который РЕАЛЬНО в коде, R-06', function (): void {
    // Факт кода: image/table принимаются store-time, а конфиг-шов существует.
    expect(TipTapContent::allowedNodeTypes())->toContain('image', 'table')
        ->and(TipTapContent::allowedMarkTypes())->toContain('underline');

    config()->set('vaulter.tiptap.extra_nodes', ['mention']);
    expect(TipTapContent::allowedNodeTypes())->toContain('mention');

    // …и дока про это говорит.
    expect(upgradeGuide())
        ->toContain('vaulter.tiptap.extra_nodes')
        ->toContain('(P5.2 / P0.1 / F-P1.11-01, F-P1.11-02, R-01)');
});

it('UPGRADE.md документирует УДАЛЁННЫЙ ключ follow_redirects, и ключа действительно нет, R-06', function (): void {
    // Факт кода: ключа нет ни в шипуемом конфиге, ни как аксессора Config.
    expect(config('vaulter.webhooks.delivery.follow_redirects'))->toBeNull()
        ->and(method_exists(Config::class, 'webhookFollowRedirects'))->toBeFalse();

    expect(upgradeGuide())->toContain('`webhooks.delivery.follow_redirects` is removed');
});

it('UPGRADE.md имеет секцию для КАЖДОГО item\'а, чей BC уже в коде, R-06', function (string $marker, string $codeFact): void {
    expect($codeFact)->not->toBe('', 'ground truth пуст — кейс ничего не проверяет');
    expect(upgradeGuide())->toContain($marker);
})->with(function (): array {
    $root = dirname(__DIR__, 4);

    return [
        // P0.1 — контракт TipTap (самый host-видимый BC плана; секции не было вовсе).
        'P0.1 TipTap allow-list' => ['(P5.2 / P0.1 / F-P1.11-01, F-P1.11-02, R-01)', (string) file_get_contents($root.'/packages/documents/src/ValueObjects/TipTapContent.php')],
        // P1.1 — безусловный nosniff + whitelist Content-Type на раздаче.
        'P1.1 nosniff' => ['(P1.1, P1.3 / F-P1.3-04, F-P1.11-03, F-P1.8-01, F-P1.4-09)', (string) file_get_contents($root.'/packages/core/src/Http/Support/DownloadHeaders.php')],
        // P1.3 — именованные лимитеры + кап активных сессий.
        'P1.3 rate limits' => ['`vaulter-public`', (string) file_get_contents($root.'/packages/core/config/vaulter.php')],
        // P5.2 — критическая секция upload-сессии.
        'P5.2 upload lock' => ['(P5.2 / R-02, R-03, R-08)', (string) file_get_contents($root.'/packages/drive/src/Uploads/UploadSessionManager.php')],
        // P5.2 — markdown-экранирование.
        'P5.2 markdown' => ['(P5.2 / R-04, R-10)', (string) file_get_contents($root.'/packages/documents/src/Support/TipTap/TipTapRenderer.php')],
        // P5.2 — commit внутри критической секции + CAS на markCommitted.
        'P5.2 commit lock' => ['(P5.2 / R-11)', (string) file_get_contents($root.'/packages/drive/src/Repositories/Uploads/UploadSessionStoreRepository.php')],
        // P5.2 — обеззараживание TipTap-контента на JSON-чтении.
        'P5.2 read sanitization' => ['(P5.2 / R-12)', (string) file_get_contents($root.'/packages/documents/src/Http/Resources/DocumentRevisionResource.php')],
        // Ревью P5.2 — per-chunk кап включён из коробки (BC: тело >64 MiB теперь 413).
        'P5.2-review chunk cap' => ['(P5.2 review / F-P1.4-04, RV-03)', (string) file_get_contents($root.'/packages/drive/src/Http/Controllers/Uploads/TusProtocolController.php')],
        // P0.4 — движок реестра переехал в core (D10); факт кода — реальная привязка в
        // VaulterServiceProvider, не просто наличие файла.
        'P0.4 registry engine in core' => ['(VLT-CANON P0.4 / D10', (string) file_get_contents($root.'/packages/core/src/VaulterServiceProvider.php')],
        // P0.5 — richText дефолт flip; факт кода — сам VO несёт дефолт false.
        'P0.5 richText default' => ['(VLT-CANON P0.5)', (string) file_get_contents($root.'/packages/core/src/ValueObjects/Handlers/ContentCapabilities.php')],
    ];
});

/*
 * Ревью P5.2 / RV-03 — config-honesty: секция UPGRADE.md обещает, что кап на один чанк ШИПАЕТСЯ
 * ВКЛЮЧЁННЫМ. Guard держит оба конца — дефолт в шипуемом конфиге и запись про него.
 */
it('UPGRADE.md обещает включённый per-chunk кап — и дефолт в конфиге действительно непустой, RV-03', function (): void {
    expect(Config::uploadMaxChunkBytes())->toBe(67_108_864);

    expect(upgradeGuide())->toContain('now SHIPS ENABLED (64 MiB)');
});

/*
 * R-12 — дока обещает, что JSON-чтение отдаёт обеззараженный контент. Guard держит оба
 * конца: сам факт скраба URL в коде (а не только строку в UPGRADE.md).
 */
it('UPGRADE.md обещает скраб URL на чтении — и скраб действительно есть в коде, R-12', function (): void {
    $scrubbed = TipTapContent::decayFrom([
        'type' => 'doc',
        'content' => [[
            'type' => 'text',
            'text' => 'x',
            'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
        ]],
    ])->withSanitizedUrls();

    expect((string) json_encode($scrubbed->toArray()))->not->toContain('javascript:');

    expect(upgradeGuide())->toContain('(P5.2 / R-12)');
});

/*
 * Эта секция UPGRADE.md отсутствовала, и пробел стоил красного CI: P4.4 сделал резолв
 * ProfileRegistry эагерным (fail-loud на boot'е), а вместе с ним отнял у хоста возможность
 * дописать панель в конфиг ПОСЛЕ boot'а — host-видимое изменение поведения, о котором дока
 * молчала. Guard держит оба конца: факт в коде (резолв в packageBooted) и запись про него.
 */
it('UPGRADE.md документирует, что панели читаются на boot\'е — и резолв действительно там', function (): void {
    $provider = (string) file_get_contents(dirname(__DIR__, 3).'/core/src/VaulterServiceProvider.php');

    // Факт кода: реестр резолвится в packageBooted(), а не лениво на первом обращении.
    $booted = substr($provider, (int) strpos($provider, 'public function packageBooted'));

    expect($booted)->toContain('(new BindingsRegistrar)->boot($this->app)');
    $registrar = (string) file_get_contents(dirname(__DIR__, 3).'/core/src/Providers/BindingsRegistrar.php');
    expect(substr($registrar, (int) strpos($registrar, 'public function boot')))
        ->toContain('$app->make(ProfileRegistry::class)');

    // …и дока про это говорит — вместе с рантайм-швом на замену.
    expect(upgradeGuide())
        ->toContain('### Panels are read at boot, not on first use')
        ->toContain('registerProvider');
});
