<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Access\OperationGuard;
use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Registries\DocumentExporterRegistry;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Handlers\FileDescriptorFactory;
use Vaulter\Handlers\FileHandlerRegistry;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\LoadTier;

/**
 * A READ-ONLY evaluator (no persistence, no Request) that exports a DOCUMENT NODE
 * (§7 / M4a) through the format registry → exporter seam. It resolves the node's head
 * content, routes it through the handler (the handler must advertise editable OR
 * previewable to be exportable) and renders it with the registered
 * {@see DocumentExporter} for the requested format (config(
 * 'vaulter-documents.exporters') map / Documents::exporters()->register()).
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
        private readonly DocumentReadRepository $documents,
        private readonly FileHandlerRegistry $handlers,
        private readonly DocumentExporterRegistry $exporters,
        private readonly FileDescriptorFactory $descriptors,
        private readonly ExportFilenameResolver $filenames,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * Export a document node's working state — or $revision, a checkpoint the caller
     * already resolved inside this node's document — in the given format. The handler
     * resolved for the node must be exportable (editable/previewable); otherwise the
     * format set is empty and the export is rejected by the exporter lookup.
     */
    public function export(OperationContext $context, Node $node, string $format, ?DocumentRevision $revision = null): DocumentExport
    {
        $node = $this->assertContentAvailable($context, $node);
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        return $this->metrics->timing(
            name: 'vaulter_export_duration_seconds',
            operation: function () use ($node, $format, $revision): DocumentExport {
                $exporter = $this->exporters->get($format);
                $content = $this->contentOf($node, $revision);

                return new DocumentExport(
                    format: $exporter->format(),
                    content: $exporter->export($content),
                    mimeType: $exporter->mimeType(),
                    filename: $this->filenames->resolve($node, $exporter->extension()),
                );
            },
            labels: [
                'format' => $format,
                'tier' => LoadTier::label(),
            ],
        );
    }

    /** @internal Inline documents have no FileRevision scan evidence; their lifecycle is separate. */
    public function assertContentAvailable(OperationContext $context, Node $node): Node
    {
        $node = app(NodeLocator::class)->find($context, $node);
        app(OperationGuard::class)->authorize($context, Ability::Download, $node, OperationKind::ContentEgress);
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->id);
        }

        return $node;
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

        return $this->exporters->formats();
    }

    /**
     * The working-state content shaped for the node's resolved handler (P0.5,
     * kind-neutral): rich text decays to {@see TipTapContent} (never throws — see
     * {@see DocumentRevision::tipTapContent()}); any other editable handler's content
     * is an opaque snapshot, returned verbatim as {@see OpaqueDocumentContent}. A
     * non-rich-text node used to decay to an EMPTY TipTap doc here and export as
     * blank content instead of being refused by the exporter's narrowing guard
     * ({@see UnsupportedDocumentContentException}) — it now reaches the guard as
     * its own shape.
     */
    private function contentOf(Node $node, ?DocumentRevision $revision): DocumentContent
    {
        $head = $revision ?? $this->documents->forNode($node);

        if ($head === null) {
            throw NodeNotFoundException::forId($node->id);
        }

        $descriptor = $this->descriptors->forNode($node);
        $handler = $this->handlers->resolve($descriptor, Capability::Preview);

        if (!$handler->capabilities($descriptor)->richText) {
            return $head->opaqueContent();
        }

        return $head->tipTapContent();
    }
}
