<?php

declare(strict_types=1);

namespace Vaulter\Documents\Types;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

/**
 * Stands in for a type nothing registers (its extension was removed). Never
 * registered: the content is shown as opaque JSON through the `vaulter/unsupported`
 * viewer and the document is read-only until the type comes back.
 */
#[Internal]
final readonly class UnknownDocumentType implements DocumentType
{
    public function __construct(private string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->key;
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
        return new ViewerDescriptor(key: 'vaulter/unsupported');
    }

    public function capabilities(): ContentCapabilities
    {
        return new ContentCapabilities(editable: false, previewable: false, downloadable: false, thumbnailable: false, collaborative: false);
    }
}
