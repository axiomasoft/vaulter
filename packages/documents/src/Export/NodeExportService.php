<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Handlers\DocumentExporter;
use Vaulter\Documents\Contracts\Export\ExportFilenameResolver;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Engines\DocumentFileDescriptorFactory;
use Vaulter\Engines\DocumentHandlerRegistry;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Exceptions\Documents\UnsupportedDocumentContentException;
use Vaulter\Models\Drive\Node;
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
 * registry_and_its_handlers_are_final` covers this class alongside `Vaulter\
 * Documents\Engines\Handlers` — un-finalising it would weaken a P4.1
 * anti-self-deception gate) — the filename is delegated to the injected {@see ExportFilenameResolver},
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

    /**
     * The version content shaped for the node's resolved handler (P0.5, kind-neutral):
     * a rich-text handler's head decays to {@see TipTapContent} (never throws — see
     * {@see NodeDocumentVersion::tipTapContent()}); any other editable handler's head
     * is an opaque snapshot, returned verbatim as {@see OpaqueDocumentContent}. A
     * non-rich-text node used to decay to an EMPTY TipTap doc here and export as
     * blank content instead of being refused by the exporter's narrowing guard
     * ({@see UnsupportedDocumentContentException}) — it now reaches the guard as
     * its own shape.
     */
    private function contentOf(Node $node): DocumentContent
    {
        $head = $this->versions->head($node) ?? $this->versions->latest($node);

        if ($head === null) {
            return TipTapContent::empty();
        }

        $descriptor = $this->descriptors->forNode($node);
        $handler = $this->handlers->resolve($descriptor, Capability::Preview);

        if (!$handler->capabilities($descriptor)->richText) {
            return $head->opaqueContent();
        }

        return $head->tipTapContent();
    }
}
