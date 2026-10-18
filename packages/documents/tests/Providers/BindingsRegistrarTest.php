<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\CoreModule;
use Vaulter\Documents\Content\DocumentContentLifecycle;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\DocumentsModule;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Providers\BindingsRegistrar;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Documents\Types\MarkdownDocumentType;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\DriveModule;
use Vaulter\Kernel;
use Vaulter\Media\MediaModule;
use Vaulter\Modules\Activation;
use Vaulter\Modules\InvalidModuleGraph;
use Vaulter\Modules\ModuleApplier;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCompiler;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\SlotContribution;
use Vaulter\Modules\VaulterModule;

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
        'vaulter-drive' => ['handlers' => ['Host\\Handler']],
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
        ->and($config->get('vaulter-drive.handlers'))->toBe(['Host\\Handler'])
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

it('contributes the document presenter and lifecycle to the drive slots, and no file handler (PLAN2.P4.3)', function (): void {
    $extensions = app(Kernel::class)->extensions();

    expect($extensions->handler(KindPresenter::class, 'document'))->toBeInstanceOf(DocumentPresenter::class)
        ->and($extensions->handler(NodeContentLifecycle::class, 'document'))->toBeInstanceOf(DocumentContentLifecycle::class)
        ->and(array_map(static fn (SlotContribution $c): string => $c->module, $extensions->contributions(FileHandler::class)))->not->toContain('documents');
});

it('shares the sanitizer with the existing scheme allow-list', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository);

    (new BindingsRegistrar)->register($app);

    $sanitizer = $app->make(HtmlSanitizerInterface::class);
    $clean = $sanitizer->sanitize('<a href="javascript:alert(1)">unsafe</a><a href="https://example.com">safe</a><a href="mailto:hi@example.com">mail</a>');
    $decoded = html_entity_decode(string: $clean, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
    expect($app->make(HtmlSanitizerInterface::class))->toBe($sanitizer)
        ->and($decoded)->not->toContain('javascript:')
        ->and($decoded)->toContain('https://example.com', 'mailto:hi@example.com');
});

it('provides the filename seam and leaves the optional collaboration transport unselected without its package', function (): void {
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->require(ExportFilenameResolver::class))->toBeInstanceOf(DefaultExportFilenameResolver::class)
        ->and($capabilities->has(CollaborationDriver::class))->toBeFalse();
});

it('fills the type and exporter registries from the module contributions, once', function (): void {
    expect(array_keys(app(DocumentTypeRegistry::class)->all()))->toBe([MarkdownDocumentType::KEY, RichTextDocumentType::KEY])
        ->and(array_keys(app(DocumentExporterRegistry::class)->all()))->toBe(['vaulter/html', 'vaulter/markdown']);
});

it('refuses a graph in which two modules contribute the same document type or exporter key', function (string $slot, string $key): void {
    $rival = new class($slot, $key) implements VaulterModule
    {
        public function __construct(private readonly string $slot, private readonly string $key) {}

        public function id(): ModuleId
        {
            return new ModuleId('rival');
        }

        public function version(): string
        {
            return '1.0';
        }

        public function activation(): Activation
        {
            return Activation::Auto;
        }

        public function requires(): array
        {
            return ['documents' => '^1.0'];
        }

        public function register(ModuleBuilder $module): void
        {
            $module->slot($this->slot, $this->slot === DocumentType::class ? RichTextDocumentType::class : HtmlExporter::class, 'rival.contribution', key: $this->key);
        }
    };

    $graph = (new ModuleCompiler)->compile([new CoreModule, new MediaModule, new DriveModule, new DocumentsModule, $rival], []);

    expect($graph)->toBeInstanceOf(InvalidModuleGraph::class)
        ->and($graph->reasons)->toContain(sprintf('slot %s has the key "%s" more than once', $slot, $key));
})->with([
    'type' => [DocumentType::class, RichTextDocumentType::KEY],
    'exporter' => [DocumentExporter::class, 'vaulter/html'],
]);
