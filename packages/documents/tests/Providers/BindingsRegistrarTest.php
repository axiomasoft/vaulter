<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Engines\Handlers\UniverHandler;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Providers\BindingsRegistrar;

it('merges defaults in a fresh container while retaining host models, handlers and exporters', function (): void {
    $app = new Application;
    Container::setInstance($this->app);
    $config = new Repository(['vaulter' => [
        'models' => ['node_comment' => 'Host\\Comment'],
        'handlers' => ['Host\\Handler'],
        'exporters' => ['html' => 'Host\\Exporter'],
    ]]);
    $app->instance('config', $config);

    (new BindingsRegistrar($app))->register();

    expect($config->get('vaulter.models.node_comment'))->toBe('Host\\Comment')
        ->and($config->get('vaulter.models.node_document_version'))->toBe(DocumentRevision::class)
        ->and($config->get('vaulter.handlers'))->toContain(TipTapHandler::class, 'Host\\Handler')
        ->and($config->get('vaulter.exporters.html'))->toBe('Host\\Exporter')
        ->and($config->get('vaulter.exporters.markdown'))->toBe(MarkdownExporter::class);
});

it('preserves cached configuration without adding defaults', function (): void {
    $app = Mockery::mock(Application::class)->makePartial();
    $app->shouldReceive('configurationIsCached')->once()->andReturn(true);
    $values = ['vaulter' => ['models' => ['node_comment' => 'Cached\\Comment']]];
    $config = new Repository($values);
    $app->instance('config', $config);
    $app->alias('config', ConfigRepository::class);

    (new BindingsRegistrar($app))->register();

    expect($config->all())->toBe($values);
});

it('tags the spreadsheet handler only for the enabled feature', function (mixed $enabled, bool $tagged): void {
    $app = new Application;
    Container::setInstance($this->app);
    $app->instance('config', new Repository(['vaulter' => ['features' => ['spreadsheets' => $enabled]]]));

    (new BindingsRegistrar($app))->register();

    $handlers = iterator_to_array($app->tagged('vaulter.file_handlers'));
    expect($handlers)->toHaveCount($tagged ? 1 : 0);
    if ($tagged) {
        expect($handlers[0])->toBeInstanceOf(UniverHandler::class);
    }
})->with([[true, true], [false, false], ['true', false]]);

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
