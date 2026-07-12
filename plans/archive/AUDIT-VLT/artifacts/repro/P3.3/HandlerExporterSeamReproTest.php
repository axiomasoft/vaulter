<?php

declare(strict_types=1);

/*
 | AUDIT-VLT · P3.3 repro (read-only, D6) — формат-шов handler/exporter: три
 | источника регистрации handler'а (tagged/config/runtime) + дедуп по FQCN (C1),
 | runtime register() перепраймивает merged-set (C2), null-object fallback (C3),
 | и runtime-регистрация экспортёра (C7). Живёт ВНЕ пакетных tests-каталогов (D6).
 | Запуск из каталога packages/documents:
 |   /usr/bin/php8.4 vendor/bin/pest ../../plans/AUDIT-VLT/artifacts/repro/P3.3
 | Явно подключает Vaulter\Documents\Tests\TestCase (тот же bootstrap, что штатные
 | suites), т.к. файл лежит вне сконфигурированного testsuite и авто-binding uses()
 | не ловит.
*/

use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\Documents\Documents\DocumentHandlerRegistry;
use Vaulter\Documents\Documents\Handlers\DownloadOnlyHandler;
use Vaulter\Documents\Enums\Capability;
use Vaulter\Documents\Tests\TestCase;
use Vaulter\ValueObjects\Documents\DocumentCapabilities;
use Vaulter\ValueObjects\Documents\FileDescriptor;
use Vaulter\ValueObjects\Documents\HandlerUi;
use Vaulter\VaulterManager;

uses(TestCase::class)->in(__DIR__);

/**
 * A throwaway handler claiming a mime type no other built-in handler claims, so
 * resolution outcome is unambiguous.
 */
final class P33ReproHandler implements DocumentHandler
{
    public function id(): string
    {
        return 'p33-repro';
    }

    public function mimeTypes(): array
    {
        return ['application/x-p33-repro'];
    }

    public function extensions(): array
    {
        return ['p33repro'];
    }

    public function priority(): int
    {
        return 500;
    }

    public function capabilities(FileDescriptor $file): DocumentCapabilities
    {
        return new DocumentCapabilities(editable: false, previewable: true, downloadable: true, thumbnailable: false, collaborative: false);
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        return new HandlerUi(component: 'P33Repro', route: null, props: []);
    }
}

function p33Descriptor(): FileDescriptor
{
    return new FileDescriptor(
        nodeId: 1,
        filename: 'file.p33repro',
        extension: 'p33repro',
        claimedMime: 'application/x-p33-repro',
        detectedMime: 'application/x-p33-repro',
        size: 10,
    );
}

it('C1: registers a handler via config(vaulter.handlers) and resolves it (config source works)', function (): void {
    config(['vaulter.handlers' => array_merge(config('vaulter.handlers', []), [P33ReproHandler::class])]);

    /** @var DocumentHandlerRegistry $registry */
    $registry = app(DocumentHandlerRegistry::class);

    $resolved = $registry->resolve(p33Descriptor(), Capability::Preview);

    expect($resolved)->toBeInstanceOf(P33ReproHandler::class);
});

it('C1: dedups a handler present in config AND registered again at runtime (same FQCN, one entry in all())', function (): void {
    config(['vaulter.handlers' => array_merge(config('vaulter.handlers', []), [P33ReproHandler::class])]);

    /** @var DocumentHandlerRegistry $registry */
    $registry = app(DocumentHandlerRegistry::class);
    // Force the merged set to build from the config source first.
    $registry->resolve(p33Descriptor(), Capability::Preview);

    // Now register the SAME class at runtime.
    $registry->register(P33ReproHandler::class);

    $matching = array_filter($registry->all(), static fn (DocumentHandler $h): bool => $h::class === P33ReproHandler::class);

    expect($matching)->toHaveCount(1);
});

it('C2: runtime register() re-primes the merged set — a handler unknown to config/tagged sources resolves after registerHandler()', function (): void {
    /** @var VaulterManager $manager */
    $manager = app(VaulterManager::class);

    // Nothing claims this mime before registration.
    /** @var DocumentHandlerRegistry $registry */
    $registry = app(DocumentHandlerRegistry::class);
    $before = $registry->resolve(p33Descriptor(), Capability::Preview);
    expect($before)->toBeInstanceOf(DownloadOnlyHandler::class);

    $manager->registerHandler(P33ReproHandler::class);

    $after = $registry->resolve(p33Descriptor(), Capability::Preview);
    expect($after)->toBeInstanceOf(P33ReproHandler::class);
});

it('C3: resolve() never returns null and the DownloadOnly null-object is the guaranteed terminal for an unmatched mime/extension', function (): void {
    /** @var DocumentHandlerRegistry $registry */
    $registry = app(DocumentHandlerRegistry::class);

    $descriptor = new FileDescriptor(
        nodeId: 2,
        filename: 'file.totally-unknown-ext-zzz',
        extension: 'totally-unknown-ext-zzz',
        claimedMime: 'application/x-totally-unknown-zzz',
        detectedMime: 'application/x-totally-unknown-zzz',
        size: 1,
    );

    $resolved = $registry->resolve($descriptor, Capability::Download);

    expect($resolved)->toBeInstanceOf(DownloadOnlyHandler::class);
});

it('C7: VaulterManager::registerExporter() is a runtime registration path symmetric to registerHandler() (instance-only, no FQCN)', function (): void {
    /** @var VaulterManager $manager */
    $manager = app(VaulterManager::class);

    $before = $manager->exporterFormats();
    expect($before)->not->toContain('p33repro');

    $manager->registerExporter(new class implements \Vaulter\Contracts\Documents\DocumentExporter
    {
        public function format(): string
        {
            return 'p33repro';
        }

        public function mimeType(): string
        {
            return 'application/x-p33repro';
        }

        public function extension(): string
        {
            return 'p33';
        }

        public function export(\Vaulter\ValueObjects\Documents\TipTapContent $content): string
        {
            return '';
        }
    });

    $after = $manager->exporterFormats();
    expect($after)->toContain('p33repro');
});
