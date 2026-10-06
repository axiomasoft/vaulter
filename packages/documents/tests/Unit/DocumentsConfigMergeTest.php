<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

final class ConfigMergeHostNodeComment extends NodeComment {}

/** The module owns its config root and contributes built-in handlers through tags. */
it('loads document defaults under their owner and resolves tagged document handlers (P2.5)', function (): void {
    expect(config('vaulter-documents.models.node_comment'))->toBe(NodeComment::class)
        ->and(config('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class)
        ->and(config('vaulter.handlers'))->toBe([])
        ->and(config('vaulter.handlers'))->not->toContain(TipTapHandler::class)
        ->and(config('vaulter-documents.exporters.html'))->toBe(HtmlExporter::class)
        ->and(config('vaulter-documents.exporters.markdown'))->toBe(MarkdownExporter::class);

    $tagged = array_map(static fn (object $handler): string => $handler::class, iterator_to_array(app()->tagged('vaulter.file_handlers')));
    expect($tagged)->toContain(PreviewHandler::class, DownloadOnlyHandler::class, TipTapHandler::class);

    $registry = app(FileHandlerRegistry::class);
    $handler = $registry->resolve(new FileDescriptor(
        nodeId: 1,
        filename: 'Document.vdoc',
        detectedMime: TipTapHandler::DOCUMENT_MIME,
    ));

    expect($handler)->toBeInstanceOf(TipTapHandler::class);

});

it('preserves a valid host model subclass when the documents provider registers again (P2.5)', function (): void {
    config(['vaulter-documents.models.node_comment' => ConfigMergeHostNodeComment::class]);

    app()->register(VaulterDocumentsServiceProvider::class, force: true);

    expect(app(DocumentsConfig::class)->model('node_comment'))->toBe(ConfigMergeHostNodeComment::class)
        ->and(config('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class);
});

it('preserves cached module configuration during registration while tagging its handler (P2.5)', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturn(true);
    $values = ['vaulter-documents' => [
        'models' => ['node_comment' => ConfigMergeHostNodeComment::class],
        'exporters' => ['html' => HtmlExporter::class],
    ]];
    $config = new Repository($values);
    $app->instance('config', $config);

    // Shipped defaults are loaded later by the shared normalizer at boot.
    (new BindingsRegistrar($app))->register();

    expect($config->all())->toBe($values)
        ->and($app->make(DocumentsConfig::class)->model('node_comment'))->toBe(ConfigMergeHostNodeComment::class);
    expect(iterator_to_array($app->tagged('vaulter.file_handlers')))->toHaveCount(1)
        ->and(iterator_to_array($app->tagged('vaulter.file_handlers'))[0])->toBeInstanceOf(TipTapHandler::class);
});
