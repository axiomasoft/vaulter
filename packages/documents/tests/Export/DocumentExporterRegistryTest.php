<?php

declare(strict_types=1);

use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\DuplicateDocumentExporterException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Exceptions\Registry\RegistryFrozenException;

/** @param list<string> $types */
function stubExporter(string $key, string $format, array $types = ['vaulter/rich-text']): DocumentExporter
{
    return new class($key, $format, $types) implements DocumentExporter
    {
        /** @param list<string> $types */
        public function __construct(private string $key, private string $format, private array $types) {}

        public function key(): string
        {
            return $this->key;
        }

        public function format(): string
        {
            return $this->format;
        }

        public function mimeType(): string
        {
            return 'text/plain';
        }

        public function extension(): string
        {
            return 'txt';
        }

        public function documentTypes(): array
        {
            return $this->types;
        }

        public function export(DocumentContent $content, DocumentType $type): string
        {
            return $this->key;
        }
    };
}

function openRegistry(): DocumentExporterRegistry
{
    return new DocumentExporterRegistry(app());
}

it('resolves an exporter by document type and format', function (): void {
    $registry = openRegistry();
    $registry->register(HtmlExporter::class);
    $registry->register(stubExporter('acme/pdf', 'pdf', ['acme/kanban']));

    expect($registry->for('vaulter/rich-text', 'html'))->toBeInstanceOf(HtmlExporter::class)
        ->and($registry->for('acme/kanban', 'pdf')->key())->toBe('acme/pdf')
        ->and($registry->formatsFor('vaulter/rich-text'))->toBe(['html'])
        ->and($registry->formatsFor('acme/kanban'))->toBe(['pdf'])
        ->and($registry->formatsFor('acme/unknown'))->toBe([]);
});

it('names only the formats the type allows when none matches', function (): void {
    $registry = openRegistry();
    $registry->register(HtmlExporter::class);
    $registry->register(stubExporter('acme/pdf', 'pdf', ['acme/kanban']));

    try {
        $registry->for('vaulter/rich-text', 'pdf');
        $this->fail('expected UnknownExportFormatException');
    } catch (UnknownExportFormatException $e) {
        expect($e->getMessage())->toContain('available: [html]')
            ->and($e->getMessage())->not->toContain('acme/kanban');
    }
});

it('refuses two exporters for one (type, format) pair and the same key twice', function (): void {
    $registry = openRegistry();
    $registry->register(stubExporter('acme/one', 'txt'));

    expect(fn () => $registry->register(stubExporter('acme/two', 'txt')))->toThrow(DuplicateDocumentExporterException::class, 'both render format "txt"')
        ->and(fn () => $registry->register(stubExporter('acme/one', 'other')))->toThrow(DuplicateDocumentExporterException::class, 'registered twice');

    // Another type for the same format is not a collision.
    $registry->register(stubExporter('acme/three', 'txt', ['acme/kanban']));
    expect($registry->formatsFor('acme/kanban'))->toBe(['txt']);
});

it('replaces an exporter only under its own key and only while open', function (): void {
    $registry = openRegistry();
    $registry->register(stubExporter('acme/one', 'txt'));
    $registry->replace('acme/one', stubExporter('acme/one', 'txt'));

    expect(fn () => $registry->replace('acme/one', stubExporter('acme/other', 'txt')))->toThrow(InvalidConfigurationException::class, 'not "acme/one"')
        ->and(fn () => $registry->replace('acme/missing', stubExporter('acme/missing', 'txt')))->toThrow(InvalidConfigurationException::class, 'not registered');

    $registry->freeze();
    expect(fn () => $registry->register(stubExporter('acme/late', 'late')))->toThrow(RegistryFrozenException::class)
        ->and(fn () => $registry->replace('acme/one', stubExporter('acme/one', 'txt')))->toThrow(RegistryFrozenException::class);
});

it('rejects a malformed key, a format that is not a URL segment and a non-exporter class', function (): void {
    $registry = openRegistry();

    expect(fn () => $registry->register(stubExporter('Bad Key', 'txt')))->toThrow(InvalidConfigurationException::class, 'vendor/name')
        ->and(fn () => $registry->register(stubExporter('acme/x', 'a/b')))->toThrow(InvalidConfigurationException::class, 'URL segment')
        ->and(fn () => $registry->register(stdClass::class))->toThrow(InvalidConfigurationException::class, 'implementing');
});

it('boots with both built-in exporters registered and frozen', function (): void {
    $exporters = Documents::exporters();

    expect(array_keys($exporters->all()))->toBe(['vaulter/html', 'vaulter/markdown'])
        ->and($exporters->isFrozen())->toBeTrue()
        ->and($exporters->formatsFor('vaulter/markdown'))->toBe(['html', 'markdown'])
        ->and($exporters->formatsFor('vaulter/spreadsheet'))->toBe([]);
});
