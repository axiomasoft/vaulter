<?php

declare(strict_types=1);

namespace Vaulter\Documents\Presentation;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Documents\DocumentSyncState;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
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

    public function present(NodeSnapshot $node): NodePresentation
    {
        [$key, $schemaVersion, $protocol, $format, $hasCheckpoint] = $this->head($node);
        $type = $this->decoder->type($key);
        $features = $type->features();
        $viewer = $type->viewer();
        $writable = $this->decoder->schemaWritable($type, $schemaVersion);
        $collaborative = false;
        if ($writable && $type instanceof SupportsCollaboration && $protocol !== null && $format !== null
            && $protocol === $type->collaborationProtocol()
            && CollaborationStateBinding::acceptsFormat($protocol, $type->collaborationStateFormat(), $format)
            && in_array($protocol, $this->kernel->extensions()->handlerKeys(CollaborationProtocolDriver::class), true)) {
            $driver = $this->kernel->extensions()->handler(CollaborationProtocolDriver::class, $protocol);
            if ($driver->protocol() !== $protocol) {
                throw new CollaborationProtocolContractException;
            }
            $collaborative = $driver->supportsState($key, $format, $schemaVersion, $hasCheckpoint, CollaborationMode::Read);
        }
        $capabilities = new ContentCapabilities(
            editable: $features->editable && $writable,
            previewable: $features->previewable,
            downloadable: $features->downloadable,
            thumbnailable: $features->thumbnailable,
            collaborative: $collaborative,
        );

        return new NodePresentation(
            id: null,
            capabilities: $capabilities,
            ui: new ViewerDescriptor(key: $viewer->key, props: $viewer->props),
            documentType: $key,
            typeAvailable: $this->decoder->available($key),
        );
    }

    /** @return array{string, int, ?string, ?string, bool} */
    private function head(NodeSnapshot $node): array
    {
        $head = (new Document)->getTable();
        $sync = (new DocumentSyncState)->getTable();
        $document = Document::query()
            ->leftJoin($sync, $sync.'.document_id', '=', $head.'.id')
            ->where($head.'.node_id', $node->id)
            ->toBase()
            ->first([$head.'.type', $head.'.schema_version', $sync.'.collab_protocol', $sync.'.collab_state_format', $sync.'.collab_state_size']);

        if ($document === null) {
            return ['', 1, null, null, false];
        }

        return [
            is_string($document->type) ? $document->type : '',
            is_numeric($document->schema_version) ? (int) $document->schema_version : 1,
            is_string($document->collab_protocol) ? $document->collab_protocol : null,
            is_string($document->collab_state_format) ? $document->collab_state_format : null,
            $document->collab_state_size !== null,
        ];
    }
}
