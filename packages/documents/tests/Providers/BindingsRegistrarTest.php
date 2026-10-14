<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;

/** What the module kernel does with the documents module's config declaration. */
function applyDocumentsConfig(Application $app, bool $normalize = true): void
{
    $builder = new ModuleBuilder(new ModuleId('documents'));
    (new DocumentsModule)->register($builder);
    $applier = new ModuleApplier($app);
    foreach ($builder->configs() as $root => $file) {
        $applier->config($root, $file);
    }
    if ($normalize) {
        $applier->normalizeConfig();
    }
}

it('normalizes module defaults in a fresh container while retaining host overrides', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository([
        'vaulter' => ['handlers' => ['Host\\Handler']],
        'vaulter-documents' => [
            'models' => ['node_comment' => 'Host\\Comment'],
            'exporters' => ['Host\\Exporter'],
        ],
    ]);
    $app->instance('config', $config);
    $app->singleton(ConfigNormalizer::class);

    // The module kernel merges the documents module's config and runs the shared normalizer once the graph is sealed.
    applyDocumentsConfig($app);
    (new BindingsRegistrar)->register($app);

    expect($config->get('vaulter-documents.models.node_comment'))->toBe('Host\\Comment')
        ->and($config->get('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class)
        ->and($config->get('vaulter.handlers'))->toBe(['Host\\Handler'])
        ->and($app->tagged('vaulter.file_handlers'))->toBeIterable()
        ->and($config->get('vaulter-documents.exporters'))->toContain('Host\\Exporter', MarkdownExporter::class);
});

it('preserves cached configuration without adding defaults', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturn(true);
    $values = ['vaulter-documents' => ['models' => ['node_comment' => 'Cached\\Comment']]];
    $config = new Repository($values);
    $app->instance('config', $config);
    $app->alias('config', ConfigRepository::class);

    applyDocumentsConfig($app, normalize: false);
    (new BindingsRegistrar)->register($app);

    expect($config->all())->toBe($values);
});

it('tags the document presenter and no file handler (PLAN2.P4.3)', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository);

    (new BindingsRegistrar)->register($app);

    expect(iterator_to_array($app->tagged('vaulter.file_handlers')))->toBe([])
        ->and(iterator_to_array($app->tagged('vaulter.kind_presenters'))[0])->toBeInstanceOf(DocumentPresenter::class);
});

it('binds the filename seam and shares the sanitizer with the existing scheme allow-list', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository);

    (new BindingsRegistrar)->register($app);

    $sanitizer = $app->make(HtmlSanitizerInterface::class);
    $clean = $sanitizer->sanitize('<a href="javascript:alert(1)">unsafe</a><a href="https://example.com">safe</a><a href="mailto:hi@example.com">mail</a>');
    $decoded = html_entity_decode(string: $clean, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
    expect($app->make(ExportFilenameResolver::class))->toBeInstanceOf(DefaultExportFilenameResolver::class)
        ->and($app->make(HtmlSanitizerInterface::class))->toBe($sanitizer)
        ->and($decoded)->not->toContain('javascript:')
        ->and($decoded)->toContain('https://example.com', 'mailto:hi@example.com');
});
