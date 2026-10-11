<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Documents\Export\HtmlExporter;
use Vaulter\Documents\Export\MarkdownExporter;
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;
use Vaulter\Documents\Testing\Contracts\ExporterContractTests;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenDocumentType;
use Vaulter\Documents\Tests\Fixtures\Testing\BrokenExporter;

/**
 * Every contract suite is proved twice: green on a reference implementation and red
 * on one that breaks the contract, so a suite that stops catching a violation fails here.
 */
uses(DocumentTypeContractTests::class);

it('accepts the built-in exporters', function (): void {
    $contract = new ExporterContractTests;

    $contract->assertExporterContract(app(HtmlExporter::class));
    $contract->assertExporterContract(app(MarkdownExporter::class));
});

it('rejects an exporter whose output changes between calls', function (): void {
    (new ExporterContractTests)->assertExporterContract(new BrokenExporter);
})->throws(AssertionFailedError::class, 'same bytes');

it('rejects a document type whose key is not vendor/name', function (): void {
    $this->assertDocumentTypeContract(new BrokenDocumentType);
})->throws(AssertionFailedError::class, 'vendor/name');
