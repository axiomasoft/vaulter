<?php

declare(strict_types=1);

namespace Vaulter\Documents\Presentation;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationDriver;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\NodePresentation;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;
use Vaulter\Kernel;

/**
 * Presents a document node by its document type, never by its mime: no file handler
 * serves it, the viewer and capabilities are the type's, and a type that is not
 * installed shows the read-only `vaulter/unsupported` viewer. Only the type key leaves
 * this class, never the type's class.
 */
#[Api]
final readonly class DocumentPresenter implements KindPresenter
{
    #[Internal]
    public function __construct(private ContentDecoder $decoder, private Kernel $kernel) {}

    public function kind(): NodeKind
    {
        return NodeKind::Document;
    }

    public function present(Node|NodeSnapshot $node): NodePresentation
    {
        [$key, $schemaVersion] = $this->head($node);
        $type = $this->decoder->type($key);
        $features = $type->features();
        $viewer = $type->viewer();
        $writable = $this->decoder->schemaWritable($type, $schemaVersion);
        $capabilities = new ContentCapabilities(
            editable: $features->editable && $writable,
            previewable: $features->previewable,
            downloadable: $features->downloadable,
            thumbnailable: $features->thumbnailable,
            collaborative: $writable && $type instanceof SupportsCollaboration
                && $this->kernel->capabilities()->has(CollaborationDriver::class),
        );

        return new NodePresentation(
            id: null,
            capabilities: $capabilities,
            ui: new ViewerDescriptor(key: $viewer->key, props: $viewer->props),
            documentType: $key,
            typeAvailable: $this->decoder->available($key),
        );
    }

    /** @return array{string, int} */
    private function head(Node|NodeSnapshot $node): array
    {
        if ($node instanceof NodeSnapshot && $node->document !== null) {
            return [$node->document->type, $node->document->schemaVersion];
        }

        $id = $node instanceof NodeSnapshot ? $node->id : $node->getKey();
        $document = Document::query()->where('node_id', $id)->first(['type', 'schema_version']);

        return $document === null ? ['', 1] : [$document->type, $document->schema_version];
    }
}
