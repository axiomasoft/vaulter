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

/** A type of a host or a test that claims a key; used for the key rules. */
final readonly class KeyedType implements DocumentType
{
    public function __construct(private string $key = 'vaulter/kanban') {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Keyed type';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function codec(): DocumentCodec
    {
        return new OpaqueJsonCodec;
    }

    public function defaultContent(): DocumentContent
    {
        return OpaqueContent::fromArray([]);
    }

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer(key: 'acme/keyed');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures;
    }
}
