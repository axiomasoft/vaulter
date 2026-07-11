<?php

declare(strict_types=1);

namespace Vaulter\Documents\Documents;

use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\Documents\Contracts\Export\ExportFilenameResolver;
use Vaulter\Documents\Enums\Documents\Capability;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Documents\TipTapContent;
use Vaulter\VaulterManager;

/**
 * A READ-ONLY evaluator (no persistence, no Request) that exports a DOCUMENT NODE
 * (§7 / M4a) through the format registry → exporter seam. It resolves the node's head
 * content, routes it through the handler (the handler must advertise editable OR
 * previewable to be exportable) and renders it with the registered
 * {@see DocumentExporter} for the requested format (config(
 * 'vaulter.exporters') map / VaulterManager::registerExporter).
 *
 * The existing HtmlExporter/MarkdownExporter are reused verbatim — the node-attached
 * path only adds the node→TipTapContent resolution. Heavy converters (PDF/DOCX) stay
 * a registerable seam; this service routes to whatever exporter is registered.
 *
 * The export filename scheme is a container seam, not an inheritance one (P4.5 /
 * F-P3.1-04): this class stays `final` (H.5, `ArchTest::the_document_handler_
 * registry_and_its_handlers_are_final` covers the whole `Vaulter\Documents\
 * Documents` namespace — un-finalising it would weaken a P4.1 anti-self-deception
 * gate) — the filename is delegated to the injected {@see ExportFilenameResolver},
 * which a host rebinds to its own implementation for a different naming scheme
 * (e.g. `{name}-v{version}.{ext}`) instead of forking this file.
 */
final class NodeExportService
{
    public function __construct(
        private readonly NodeDocumentVersionReadRepository $versions,
        private readonly DocumentHandlerRegistry $handlers,
        private readonly VaulterManager $manager,
        private readonly DocumentFileDescriptorFactory $descriptors,
        private readonly ExportFilenameResolver $filenames,
    ) {}

    /**
     * Export a document node's current head content in the given format. The handler
     * resolved for the node must be exportable (editable/previewable); otherwise the
     * format set is empty and the export is rejected by the exporter lookup.
     */
    public function export(Node $node, string $format): DocumentExport
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $exporter = $this->manager->exporter($format);
        $content = $this->contentOf($node);

        return new DocumentExport(
            format: $exporter->format(),
            content: $exporter->export($content),
            mimeType: $exporter->mimeType(),
            filename: $this->filenames->resolve($node, $exporter->extension()),
        );
    }

    /**
     * The export formats available for a node — the registered exporter formats,
     * but only when the node's resolved handler can export (editable or previewable).
     * A download-only node exposes no export formats.
     *
     * @return list<string>
     */
    public function availableFormats(Node $node): array
    {
        $descriptor = $this->descriptors->forNode($node);
        $handler = $this->handlers->resolve($descriptor, Capability::Preview);
        $capabilities = $handler->capabilities($descriptor);

        if (!$capabilities->editable && !$capabilities->previewable) {
            return [];
        }

        return $this->manager->exporterFormats();
    }

    private function contentOf(Node $node): TipTapContent
    {
        $head = $this->versions->head($node) ?? $this->versions->latest($node);

        return $head?->tipTapContent() ?? TipTapContent::empty();
    }
}
