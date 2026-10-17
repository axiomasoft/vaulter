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
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler;
use Vaulter\Drive\Handlers\Builtin\PreviewHandler;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\SlotContribution;

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
        ->and(config('vaulter-drive.handlers'))->toBe([])
        ->and(config('vaulter-documents.exporters'))->toBe([HtmlExporter::class, MarkdownExporter::class]);

    // The drive contributes the file handlers; the documents module only a presenter for its kind.
    $handlers = array_map(static fn (SlotContribution $c): string => $c->implementation, app(Kernel::class)->extensions()->contributions(FileHandler::class));
    expect($handlers)->toEqualCanonicalizing([PreviewHandler::class, DownloadOnlyHandler::class]);

    $presenters = array_map(static fn (SlotContribution $c): string => $c->implementation, app(Kernel::class)->extensions()->contributions(KindPresenter::class));
    expect($presenters)->toBe([DocumentPresenter::class]);
});

it('preserves a valid host model subclass when the documents provider registers again (P2.5)', function (): void {
    config(['vaulter-documents.models.node_comment' => ConfigMergeHostNodeComment::class]);

    // A provider cannot offer its module again after boot (the catalogue is sealed): the kernel applies the
    // module config once, with the host values winning — re-applying it changes nothing.
    $applier = app(ModuleApplier::class);
    $applier->config('vaulter-documents', dirname(__DIR__, 2).'/config/vaulter-documents.php');
    $applier->normalizeConfig();

    expect(app(DocumentsConfig::class)->model('node_comment'))->toBe(ConfigMergeHostNodeComment::class)
        ->and(config('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class);
});

it('preserves cached module configuration during registration and adds no file handler (P2.5)', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturn(true);
    $values = ['vaulter-documents' => [
        'models' => ['node_comment' => ConfigMergeHostNodeComment::class],
        'exporters' => [HtmlExporter::class],
    ]];
    $config = new Repository($values);
    $app->instance('config', $config);

    // Shipped defaults are loaded later by the shared normalizer at boot.
    (new ModuleApplier($app))->config('vaulter-documents', dirname(__DIR__, 2).'/config/vaulter-documents.php');
    (new BindingsRegistrar)->register($app);

    expect($config->all())->toBe($values)
        ->and($app->make(DocumentsConfig::class)->model('node_comment'))->toBe(ConfigMergeHostNodeComment::class);
    expect(iterator_to_array($app->tagged('vaulter.file_handlers')))->toBe([]);
});
