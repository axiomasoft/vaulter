<?php

declare(strict_types=1);

namespace Vaulter\Documents\Testing\Contracts;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Assert;
use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Exceptions\DuplicateDocumentExporterException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Models\Drive\Node;

/**
 * What a document exporter must keep, for the host's own exporters as well as the
 * built-in ones:
 *
 * ```php
 * (new ExporterContractTests)->assertExporterContract(new PdfExporter);
 * ```
 *
 * Run it inside the application (Testbench or the host's TestCase): the types the
 * exporter renders are read from the installed type registry, and the filename from
 * the bound resolver.
 */
#[Api]
final class ExporterContractTests
{
    public function assertExporterContract(DocumentExporter $exporter): void
    {
        Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/D', $exporter->key(), 'The key must be vendor/name.');
        Assert::assertMatchesRegularExpression('/^[a-z0-9]+$/D', $exporter->format(), 'The format is the lower-case URL segment clients ask for.');
        Assert::assertMatchesRegularExpression('/^[a-z0-9]+$/D', $exporter->extension(), 'The extension is lower-case, without the dot.');
        Assert::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/D', $exporter->mimeType(), 'The mime type must be type/subtype.');
        Assert::assertNotSame([], $exporter->documentTypes(), 'An exporter renders at least one document type.');

        $this->assertRegistration($exporter);
        $this->assertFilename($exporter);
        $this->assertDeterministicOutput($exporter);
    }

    private function assertRegistration(DocumentExporter $exporter): void
    {
        $registry = new DocumentExporterRegistry(Container::getInstance());
        $registry->register($exporter);

        foreach ($exporter->documentTypes() as $typeKey) {
            Assert::assertMatchesRegularExpression('/^[a-z0-9-]+\/[a-z0-9-]+$/D', $typeKey, 'A document type key is vendor/name.');
            Assert::assertSame($exporter, $registry->for($typeKey, $exporter->format()), sprintf('The exporter must be resolved for (%s, %s).', $typeKey, $exporter->format()));
            Assert::assertContains($exporter->format(), $registry->formatsFor($typeKey));
        }

        try {
            $registry->for('contract/not-rendered', $exporter->format());
            Assert::fail('A document type the exporter does not render must be refused.');
        } catch (UnknownExportFormatException) {
            // Expected: a type outside documentTypes() has no exporter for the format.
        }

        try {
            $registry->register($exporter);
            Assert::fail('Registering the same exporter twice must be refused.');
        } catch (DuplicateDocumentExporterException) {
            // Expected: a key, and a (type, format) pair, belong to one exporter.
        }
    }

    private function assertFilename(DocumentExporter $exporter): void
    {
        $node = new Node;
        $node->name = 'Quarterly report.draft';

        $filename = App::make(ExportFilenameResolver::class)->resolve($node, $exporter->extension());

        Assert::assertStringEndsWith('.'.$exporter->extension(), $filename, 'The filename must end with the exporter extension.');
        Assert::assertNotSame('.'.$exporter->extension(), $filename, 'The filename needs a base name.');
    }

    private function assertDeterministicOutput(DocumentExporter $exporter): void
    {
        $types = App::make(DocumentTypeRegistry::class);
        $rendered = 0;
        foreach ($exporter->documentTypes() as $typeKey) {
            $type = $types->find($typeKey);
            if ($type === null) {
                continue;
            }
            $content = $type->defaultContent();
            $first = $exporter->export($content, $type);
            Assert::assertSame($first, $exporter->export($content, $type), sprintf('Exporting the same %s content twice must give the same bytes.', $typeKey));
            $rendered++;
        }

        Assert::assertGreaterThan(0, $rendered, 'None of the exporter document types is installed, so nothing could be exported.');
    }
}
