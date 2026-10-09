<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Export;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;

/**
 * Renders a document into a file. Read-only: it creates no revision and moves neither
 * the state version nor the epoch, so exporting is safe on a document being edited.
 *
 * The caller has already located the node and passed the Download / ContentEgress
 * gate. The exporter receives decoded content only; a degraded read is exported as
 * the type reads it (never as a blank document), and a type nothing registers is
 * refused rather than exported as opaque JSON.
 */
final readonly class ExportDocumentAction
{
    public function __construct(
        private DocumentQuery $query,
        private DocumentExporterRegistry $exporters,
        private ContentDecoder $decoder,
        private ExportFilenameResolver $filenames,
        private MetricsRecorder $metrics,
    ) {}

    public function execute(OperationContext $context, ExportDocumentInput $data): DocumentExport
    {
        return $this->metrics->timing(
            name: 'vaulter_export_duration_seconds',
            operation: function () use ($data): DocumentExport {
                $document = $this->query->document($data->node);
                $type = $this->decoder->writable($document->type);
                $exporter = $this->exporters->for($type->key(), $data->format);

                $revision = $data->revision;
                $source = $revision ?? $document;
                $content = $this->decoder->read($document->type, $source->content, $source->schema_version)->content;

                return new DocumentExport(
                    bytes: $exporter->export($content, $type),
                    filename: $this->filenames->resolve($data->node, $exporter->extension()),
                    mimeType: $exporter->mimeType(),
                    format: $exporter->format(),
                    // Checkpoints carry no epoch of their own; the document's current one marks the room generation.
                    sourceEpoch: $document->collab_epoch,
                    sourceStateVersion: $revision === null ? $document->state_version : $revision->state_version,
                    sourceRevisionId: $revision === null ? null : Identity::stringify($revision->getKey()),
                );
            },
            labels: [
                'format' => $data->format,
                'tier' => LoadTier::label(),
            ],
        );
    }
}
