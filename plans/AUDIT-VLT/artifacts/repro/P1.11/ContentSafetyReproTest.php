<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Documents\Enums\ExportFormat;
use Vaulter\Documents\Support\TipTapRenderer;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * P1.11 repro — контентная безопасность. READ-ONLY аудит (D2/D6): тесты живут
 * только в каталоге repro P1.11, вне пакетных тестов.
 *
 * Демонстрируют:
 *  C7  — серверный рендер TipTap пропускает href="javascript:..." (stored XSS).
 *  C9  — исходящий HTML не санируется по схемам URL (allow-list схем отсутствует).
 *  C13 — Content-Disposition filename с CRLF: как ведёт себя движок Symfony/Laravel.
 *  C11 — DownloadController/PublicShareController не ставят X-Content-Type-Options.
 */

// ─── C7 / C9: javascript: в href проходит серверный рендер ──────────────────

it('C7: server TipTapRenderer emits javascript: href verbatim (stored XSS)', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'click me',
                'marks' => [[
                    'type' => 'link',
                    'attrs' => ['href' => 'javascript:alert(1)'],
                ]],
            ]],
        ]],
    ]);

    $html = (new TipTapRenderer())->render($doc, ExportFormat::Html);

    fwrite(STDERR, "\n[C7] HTML output: {$html}\n");

    // Уязвимость: схема javascript: не отфильтрована — ссылка исполнит JS по клику.
    expect($html)->toContain('href="javascript:alert(1)"');
});

it('C9: outgoing HTML has no URL-scheme allow-list (data: / vbscript: also pass)', function (): void {
    foreach (['data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)'] as $uri) {
        $doc = TipTapContent::fromArray([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => $uri]]],
                ]],
            ]],
        ]);

        $html = (new TipTapRenderer())->render($doc, ExportFormat::Html);
        fwrite(STDERR, "\n[C9] uri={$uri} -> {$html}\n");
        expect($html)->toContain('href="'.htmlspecialchars($uri, ENT_QUOTES).'"');
    }
});

it('C9b: markdown export keeps raw javascript: link and does not escape raw text', function (): void {
    $doc = TipTapContent::fromArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => '<script>alert(1)</script>',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
            ]],
        ]],
    ]);

    $md = (new TipTapRenderer())->render($doc, ExportFormat::Markdown);
    fwrite(STDERR, "\n[C9b] MD output: {$md}\n");

    // Сырой HTML и javascript: URL уходят в markdown как есть.
    expect($md)->toContain('<script>alert(1)</script>')
        ->and($md)->toContain('(javascript:alert(1))');
});

// ─── C13: CRLF в filename для Content-Disposition ───────────────────────────

it('C13: makeDisposition (used by Laravel streamDownload) with a CRLF filename', function (): void {
    $malicious = "a\r\nX-Injected: 1";

    $threw = false;
    $disposition = null;
    try {
        // Точно тот вызов, что делает Illuminate\Routing\ResponseFactory::streamDownload
        // -> $response->headers->makeDisposition(ATTACHMENT, $name, $fallback).
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $malicious,
            'file',
        );
    } catch (\Throwable $e) {
        $threw = true;
        fwrite(STDERR, "\n[C13] makeDisposition threw: ".$e::class.': '.$e->getMessage()."\n");
    }

    if (!$threw) {
        fwrite(STDERR, "\n[C13] disposition header value: ".var_export($disposition, true)."\n");

        // Проверяем, попадают ли сырые CR/LF в значение заголовка.
        $hasRawCrlf = str_contains((string) $disposition, "\r") || str_contains((string) $disposition, "\n");
        fwrite(STDERR, "[C13] raw CR/LF present in header value: ".var_export($hasRawCrlf, true)."\n");
    }

    // Дополнительно — реальный ResponseHeaderBag через StreamedResponse, как в контроллере.
    $resp = new StreamedResponse(function (): void {}, 200, [
        'Content-Type' => 'text/html',
        'Content-Disposition' => $disposition ?? 'attachment',
    ]);
    $sent = $resp->headers->get('Content-Disposition');
    fwrite(STDERR, "[C13] ResponseHeaderBag Content-Disposition: ".var_export($sent, true)."\n");

    expect(true)->toBeTrue(); // тест-зонд: факты в STDERR, вердикт — в находке
});

// ─── C11: отсутствие nosniff в download-путях ───────────────────────────────

it('C11: download controllers set no X-Content-Type-Options header', function (): void {
    $root = dirname(__DIR__, 5); // .../vaulter  (P1.11 -> repro -> artifacts -> AUDIT-VLT -> plans -> vaulter)
    $files = [
        $root.'/packages/drive/src/Http/Controllers/DownloadController.php',
        $root.'/packages/core/src/Http/Controllers/Sharing/PublicShareController.php',
    ];

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $has = stripos($src, 'X-Content-Type-Options') !== false || stripos($src, 'nosniff') !== false;
        fwrite(STDERR, "\n[C11] ".basename($file)." sets nosniff: ".var_export($has, true)."\n");
        expect($has)->toBeFalse();
    }
});
