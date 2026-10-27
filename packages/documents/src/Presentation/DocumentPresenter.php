<?php

declare(strict_types=1);

namespace Vaulter\Documents\Presentation;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
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
        [$key, $schemaVersion, $protocol, $format, $hasCheckpoint] = $this->head($node);
        $type = $this->decoder->type($key);
        $features = $type->features();
        $viewer = $type->viewer();
        $writable = $this->decoder->schemaWritable($type, $schemaVersion);
        $collaborative = false;
        if ($writable && $type instanceof SupportsCollaboration && $protocol !== null && $format !== null
            && $protocol === $type->collaborationProtocol() && $format === $type->collaborationStateFormat()
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
    private function head(Node|NodeSnapshot $node): array
    {
        $id = $node instanceof NodeSnapshot ? $node->id : $node->getKey();
        $document = Document::query()->where('node_id', $id)->first(['type', 'schema_version', 'collab_protocol', 'collab_state_format', 'collab_state_size']);

        return $document === null ? ['', 1, null, null, false] : [$document->type, $document->schema_version, $document->collab_protocol, $document->collab_state_format, $document->collab_state_size !== null];
    }
}
