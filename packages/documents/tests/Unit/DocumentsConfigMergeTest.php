<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Handlers\Builtin\PreviewHandler;

final class ConfigMergeHostNodeComment extends NodeComment {}

/**
 * The module owns its config root, registers its document types from it and presents
 * document nodes through a kind presenter; it adds no file handler (PLAN2.P4.3).
 */
it('loads document defaults under their owner and contributes a kind presenter, not file handlers (P2.5)', function (): void {
    expect(config('vaulter-documents.models.node_comment'))->toBe(NodeComment::class)
        ->and(config('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class)
        ->and(config('vaulter-documents.types'))->toBe([RichTextDocumentType::class, MarkdownDocumentType::class])
        ->and(config('vaulter-documents.default_type'))->toBe('vaulter/rich-text')
        ->and(config('vaulter.handlers'))->toBe([])
        ->and(config('vaulter-documents.exporters.html'))->toBe(HtmlExporter::class)
        ->and(config('vaulter-documents.exporters.markdown'))->toBe(MarkdownExporter::class);

    $tagged = array_map(static fn (object $handler): string => $handler::class, iterator_to_array(app()->tagged('vaulter.file_handlers')));
    expect($tagged)->toEqualCanonicalizing([PreviewHandler::class, DownloadOnlyHandler::class]);

    $presenters = array_map(static fn (object $presenter): string => $presenter::class, iterator_to_array(app()->tagged('vaulter.kind_presenters')));
    expect($presenters)->toBe([DocumentPresenter::class]);
});

it('preserves a valid host model subclass when the documents provider registers again (P2.5)', function (): void {
    config(['vaulter-documents.models.node_comment' => ConfigMergeHostNodeComment::class]);

    app()->register(VaulterDocumentsServiceProvider::class, force: true);

    expect(app(DocumentsConfig::class)->model('node_comment'))->toBe(ConfigMergeHostNodeComment::class)
        ->and(config('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class);
});

it('preserves cached module configuration during registration and adds no file handler (P2.5)', function (): void {
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
    expect(iterator_to_array($app->tagged('vaulter.file_handlers')))->toBe([]);
});
