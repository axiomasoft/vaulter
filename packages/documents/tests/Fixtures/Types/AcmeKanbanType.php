<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Types;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/** A third-party document type, as a host extension would ship it. */
final readonly class AcmeKanbanType implements DocumentType
{
    public function key(): string
    {
        return 'acme/kanban';
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
        return new DocumentViewer(key: 'acme/kanban-board');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: true, previewable: true, downloadable: false);
    }
}
