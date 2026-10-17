<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures\Types;

use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

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

    public function viewer(): ViewerDescriptor
    {
        return new ViewerDescriptor(key: 'acme/keyed');
    }

    public function capabilities(): ContentCapabilities
    {
        return new ContentCapabilities;
    }
}
