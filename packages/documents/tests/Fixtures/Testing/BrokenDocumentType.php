<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Testing;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

/** Breaks the type contract: the key is not vendor/name. */
final readonly class BrokenDocumentType implements DocumentType
{
    public function key(): string
    {
        return 'Kanban';
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
        return new DocumentFeatures(editable: true);
    }
}
