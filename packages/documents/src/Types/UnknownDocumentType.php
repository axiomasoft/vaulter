<?php

declare(strict_types=1);

namespace Vaulter\Documents\Types;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Content\OpaqueContent;
use Vaulter\Documents\Content\OpaqueJsonCodec;
use Vaulter\Documents\Contracts\DocumentCodec;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Data\Types\DocumentFeatures;
use Vaulter\Documents\Data\Types\DocumentViewer;

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

    public function viewer(): DocumentViewer
    {
        return new DocumentViewer(key: 'vaulter/unsupported');
    }

    public function features(): DocumentFeatures
    {
        return new DocumentFeatures(editable: false, previewable: false, downloadable: false, thumbnailable: false);
    }
}
