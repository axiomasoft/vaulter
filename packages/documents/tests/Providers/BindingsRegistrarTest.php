<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Presentation\DocumentPresenter;
use Vaulter\Documents\Providers\BindingsRegistrar;

it('normalizes module defaults in a fresh container while retaining host overrides', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository([
        'vaulter' => ['handlers' => ['Host\\Handler']],
        'vaulter-documents' => [
            'models' => ['node_comment' => 'Host\\Comment'],
            'exporters' => ['html' => 'Host\\Exporter'],
        ],
    ]);
    $app->instance('config', $config);

    (new BindingsRegistrar($app))->register();

    $normalizer = $app->make(ConfigNormalizer::class);
    $normalizer->normalize(config: $config, packageDefaults: $normalizer->packageDefaults());

    expect($config->get('vaulter-documents.models.node_comment'))->toBe('Host\\Comment')
        ->and($config->get('vaulter-documents.models.document_revision'))->toBe(DocumentRevision::class)
        ->and($config->get('vaulter.handlers'))->toBe(['Host\\Handler'])
        ->and($app->tagged('vaulter.file_handlers'))->toBeIterable()
        ->and($config->get('vaulter-documents.exporters.html'))->toBe('Host\\Exporter')
        ->and($config->get('vaulter-documents.exporters.markdown'))->toBe(MarkdownExporter::class);
});

it('preserves cached configuration without adding defaults', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturn(true);
    $values = ['vaulter-documents' => ['models' => ['node_comment' => 'Cached\\Comment']]];
    $config = new Repository($values);
    $app->instance('config', $config);
    $app->alias('config', ConfigRepository::class);

    (new BindingsRegistrar($app))->register();

    expect($config->all())->toBe($values);
});

it('tags the document presenter and no file handler (PLAN2.P4.3)', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository);

    (new BindingsRegistrar($app))->register();

    expect(iterator_to_array($app->tagged('vaulter.file_handlers')))->toBe([])
        ->and(iterator_to_array($app->tagged('vaulter.kind_presenters'))[0])->toBeInstanceOf(DocumentPresenter::class);
});

it('binds the filename seam and shares the sanitizer with the existing scheme allow-list', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository);

    (new BindingsRegistrar($app))->register();

    $sanitizer = $app->make(HtmlSanitizerInterface::class);
    $clean = $sanitizer->sanitize('<a href="javascript:alert(1)">unsafe</a><a href="https://example.com">safe</a><a href="mailto:hi@example.com">mail</a>');
    $decoded = html_entity_decode(string: $clean, flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
    expect($app->make(ExportFilenameResolver::class))->toBeInstanceOf(DefaultExportFilenameResolver::class)
        ->and($app->make(HtmlSanitizerInterface::class))->toBe($sanitizer)
        ->and($decoded)->not->toContain('javascript:')
        ->and($decoded)->toContain('https://example.com', 'mailto:hi@example.com');
});
