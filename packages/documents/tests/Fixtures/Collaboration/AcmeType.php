<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Collaboration;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/** A third-party document type, as a host extension would ship it. */
final readonly class AcmeType implements DocumentType, SupportsCollaboration
{
    public function key(): string
    {
        return 'acme/ot';
    }

    public function label(): string
    {
        return 'Kanban board';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return new OpaqueJsonCodec(maxSizeBytes: 4096);
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray(['columns' => []]);
    }

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer(key: 'acme/ot-board');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: true, downloadable: false);
    }

    public function projectFromState(string $state): ?DocumentContent
    {
        return null;
    }

    public function collaborationProtocol(): string
    {
        return 'acme-ot';
    }

    public function collaborationStateFormat(): string
    {
        return 'acme-ot-v1';
    }
}
