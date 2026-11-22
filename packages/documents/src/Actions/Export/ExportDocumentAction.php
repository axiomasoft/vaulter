<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Export;

use Packman\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Export\DocumentExporterRegistry;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\ValueObjects\DocumentExport;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Support\Identity;
use Vaulter\Support\LoadTier;

/**
 * Renders a document into a file. Read-only: it creates no revision and moves neither
 * the state version nor the epoch, so exporting is safe on a document being edited.
 *
 * The caller has already located the node and passed the Download / ContentEgress
 * gate. The exporter receives decoded content only; a degraded read is exported as
 * the type reads it (never as a blank document), and a type nothing registers is
 * refused rather than exported as opaque JSON. The working state of a document with a
 * pending-state marker is refused (409 document_state_pending); a checkpoint is not.
 */
#[Internal]
final readonly class ExportDocumentAction
{
    public function __construct(
        private DocumentQuery $query,
        private DocumentExporterRegistry $exporters,
        private ContentDecoder $decoder,
        private ExportFilenameResolver $filenames,
        private MetricsRecorder $metrics,
        private PendingStateGuard $pending,
        private NodeSnapshotMapper $nodes,
    ) {}

    /**
     * The metric label for a requested format: only a format some registered exporter
     * renders, else the fixed `unknown`. The raw `?format=` is attacker-controlled and a
     * label value is a time series — an arbitrary string would be unbounded cardinality.
     */
    private function formatLabel(string $format): string
    {
        foreach ($this->exporters->all() as $exporter) {
            if ($exporter->format() === $format) {
                return $format;
            }
        }

        return 'unknown';
    }

    public function execute(DriveContext $context, ExportDocumentInput $data): DocumentExport
    {
        return $this->metrics->timing(
            name: 'vaulter_export_duration_seconds',
            operation: function () use ($data): DocumentExport {
                $document = $this->query->document($data->node, withState: $data->revision === null, withHead: false);
                // The working state lags behind saved collaboration edits; a checkpoint is what it was.
                if ($data->revision === null) {
                    $this->pending->assertSettled($document);
                }
                $revision = $data->revision;
                $source = $revision ?? $document;
                $typeKey = $revision->type ?? $document->type;
                $type = $this->decoder->writable($typeKey);
                $exporter = $this->exporters->for($type->key(), $data->format);
                $read = $this->decoder->read($typeKey, $source->payload?->content, $source->schema_version);

                return new DocumentExport(
                    bytes: $exporter->export($read->content, $type),
                    // The authorized node, seen once as the facts a custom resolver names the file after.
                    filename: $this->filenames->resolve($this->nodes->facts($data->node), $exporter->extension()),
                    mimeType: $exporter->mimeType(),
                    format: $exporter->format(),
                    // Checkpoints carry no epoch of their own: this is the document's epoch at export time (see DocumentExport).
                    sourceEpoch: $document->collab_epoch,
                    sourceStateVersion: $revision->state_version ?? $document->state_version,
                    sourceRevisionId: $revision === null ? null : Identity::stringify($revision->getKey()),
                    lossy: !$read->lossless,
                );
            },
            labels: [
                'format' => $this->formatLabel($data->format),
                'tier' => LoadTier::label(),
            ],
        );
    }
}
